<?php

namespace Phpactor\Extension\ClassMover\Rpc;

use Phpactor\MapResolver\Resolver;
use Phpactor\Extension\ClassMover\Application\ClassReferences;
use Phpactor\Extension\Rpc\Response\OpenFileResponse;
use Phpactor\Extension\Rpc\Response\UpdateFileSourceResponse;
use Phpactor\Extension\SourceCodeFilesystem\SourceCodeFilesystemExtension;
use Phpactor\Extension\Rpc\Response\EchoResponse;
use Phpactor\Extension\Rpc\Response\FileReferencesResponse;
use Phpactor\Extension\Rpc\Response\CollectionResponse;
use Phpactor\Extension\ClassMover\Application\ClassMemberReferences;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\SourceCode;
use Phpactor\WorseReflection\Core\Offset;
use Phpactor\WorseReflection\Core\Inference\SymbolContext;
use Phpactor\ClassMover\Domain\Model\ClassMemberQuery;
use Phpactor\Extension\Rpc\Response\Input\ChoiceInput;
use Phpactor\Filesystem\Domain\FilesystemRegistry;
use Phpactor\Extension\Rpc\Response\Input\TextInput;
use Phpactor\Extension\Rpc\Handler\AbstractHandler;
use Phpactor\WorseReflection;

/**
 * TODO: Extract the responsiblities of this class, see
 *       https://github.com/phpactor/phpactor/issues/440
 */
class ReferencesHandler extends AbstractHandler
{
    const NAME = 'references';

    const PARAMETER_OFFSET = 'offset';
    const PARAMETER_SOURCE = 'source';
    const PARAMETER_MODE = 'mode';
    const PARAMETER_PATH = 'path';
    const PARAMETER_FILESYSTEM = 'filesystem';

    const MODE_FIND = 'find';
    const MODE_REPLACE = 'replace';
    const PARAMETER_REPLACEMENT = 'replacement';
    const MESSAGE_NO_REFERENCES_FOUND = 'No references found';

    /**
     * @var ClassReferences
     */
    private $classReferences;

    /**
     * @var string
     */
    private $defaultFilesystem;

    /**
     * @var ClassMemberReferences
     */
    private $classMemberReferences;

    /**
     * @var Reflector
     */
    private $reflector;

    /**
     * @var FilesystemRegistry
     */
    private $registry;

    public function __construct(
        Reflector $reflector,
        ClassReferences $classReferences,
        ClassMemberReferences $classMemberReferences,
        FilesystemRegistry $registry,
        string $defaultFilesystem = SourceCodeFilesystemExtension::FILESYSTEM_GIT
    ) {
        $this->classReferences = $classReferences;
        $this->defaultFilesystem = $defaultFilesystem;
        $this->classMemberReferences = $classMemberReferences;
        $this->reflector = $reflector;
        $this->registry = $registry;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function configure(Resolver $resolver)
    {
        $resolver->setDefaults([
            self::PARAMETER_MODE => self::MODE_FIND,
            self::PARAMETER_FILESYSTEM => $this->defaultFilesystem,
            self::PARAMETER_REPLACEMENT => null,
        ]);
        $resolver->setRequired([
            self::PARAMETER_PATH,
            self::PARAMETER_OFFSET,
            self::PARAMETER_SOURCE,
        ]);
    }

    public function handle(array $arguments)
    {
        $offset = $this->reflector->reflectOffset(
            SourceCode::fromPathAndString(
                $arguments[self::PARAMETER_PATH],
                $arguments[self::PARAMETER_SOURCE]
            ),
            Offset::fromInt($arguments[self::PARAMETER_OFFSET])
        );
        $symbolContext = $offset->symbolContext();

        if (null === $arguments[self::PARAMETER_FILESYSTEM]) {
            $this->requireInput(ChoiceInput::fromNameLabelChoicesAndDefault(
                self::PARAMETER_FILESYSTEM,
                sprintf('%s "%s" in:', ucfirst($symbolContext->symbol()->symbolType()), $symbolContext->symbol()->name()),
                array_combine($this->registry->names(), $this->registry->names()),
                $this->defaultFilesystem
            ));
        }

        if ($arguments[self::PARAMETER_MODE] === self::MODE_REPLACE) {
            $this->requireInput(TextInput::fromNameLabelAndDefault(
                self::PARAMETER_REPLACEMENT,
                'Replacement: ',
                $this->defaultReplacement($symbolContext)
            ));
        }

        if ($this->hasMissingArguments($arguments)) {
            return $this->createInputCallback($arguments);
        }

        switch ($arguments[self::PARAMETER_MODE]) {
            case self::MODE_FIND:
                return $this->findReferences($symbolContext, $arguments['filesystem']);
            case self::MODE_REPLACE:
                return $this->replaceReferences(
                    $symbolContext,
                    $arguments['filesystem'],
                    $arguments[self::PARAMETER_REPLACEMENT],
                    $arguments[self::PARAMETER_PATH],
                    $arguments[self::PARAMETER_SOURCE]
                );
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown references mode "%s"',
            $arguments['mode']
        ));
    }

    private function findReferences(SymbolContext $symbolContext, string $filesystem, string $replacement = null)
    {
        list($source, $references) = $this->performFindOrReplaceReferences($symbolContext, $filesystem);

        if (count($references) === 0) {
            return EchoResponse::fromMessage(self::MESSAGE_NO_REFERENCES_FOUND);
        }

        return CollectionResponse::fromActions([
            $this->echoMessage('Found', $symbolContext, $filesystem, $references),
            FileReferencesResponse::fromArray($references),
        ]);
    }

    private function replaceReferences(
        SymbolContext $symbolContext,
        string $filesystem,
        string $replacement,
        string $path,
        string $source
    )
    {
        $originalSource = $source;
        list($source, $references) = $this->performFindOrReplaceReferences(
            $symbolContext,
            $filesystem,
            $source,
            $replacement
        );

        if (count($references) === 0) {
            return EchoResponse::fromMessage(self::MESSAGE_NO_REFERENCES_FOUND);
        }

        $actions = [
            $this->echoMessage('Replaced', $symbolContext, $filesystem, $references),
        ];

        if ($source) {
            $actions[] = UpdateFileSourceResponse::fromPathOldAndNewSource($path, $originalSource, $source);
        }

        if (count($references)) {
            $actions[] = FileReferencesResponse::fromArray($references);
        }

        return CollectionResponse::fromActions($actions);
    }

    private function classReferences(string $filesystem, SymbolContext $symbolContext, string $source = null, string $replacement = null)
    {
        $classType = (string) $symbolContext->type();
        $references = $this->classReferences->findOrReplaceReferences($filesystem, $classType, $replacement);

        $updatedSource = null;
        if ($source) {
            $updatedSource = $this->classReferences->replaceInSource(
                $source,
                $classType,
                $replacement
            );
        }


        return [$updatedSource, $references['references']];
    }

    private function memberReferences(
        string $filesystem,
        SymbolContext $symbolContext,
        string $memberType,
        string $source = null,
        string $replacement = null
    )
    {
        $classType = (string) $symbolContext->containerType();

        $references = $this->classMemberReferences->findOrReplaceReferences(
            $filesystem,
            $classType,
            $symbolContext->symbol()->name(),
            $memberType,
            $replacement
        );

        $updatedSource = null;
        if ($source && $replacement) {
            $updatedSource = $this->classMemberReferences->replaceInSource(
                $source,
                $classType,
                $symbolContext->symbol()->name(),
                $memberType,
                $replacement
            );
        }

        return [$updatedSource, $references['references']];
    }

    private function performFindOrReplaceReferences(
        SymbolContext $symbolContext,
        string $filesystem,
        string $source = null,
        string $replacement = null
    )
    {
        switch ($symbolContext->symbol()->symbolType()) {
            case Symbol::CLASS_:
                return $this->classReferences($filesystem, $symbolContext, $source, $replacement);
            case Symbol::METHOD:
                return $this->memberReferences($filesystem, $symbolContext, ClassMemberQuery::TYPE_METHOD, $source, $replacement);
            case Symbol::PROPERTY:
                return $this->memberReferences($filesystem, $symbolContext, ClassMemberQuery::TYPE_PROPERTY, $source, $replacement);
            case Symbol::CONSTANT:
                return $this->memberReferences($filesystem, $symbolContext, ClassMemberQuery::TYPE_CONSTANT, $source, $replacement);
        }

        throw new \RuntimeException(sprintf(
            'Cannot find references for symbol type "%s"',
            $symbolContext->symbol()->symbolType()
        ));
    }

    private function echoMessage(string $action, SymbolContext $symbolContext, string $filesystem, array $references): EchoResponse
    {
        $count = array_reduce($references, function ($count, $result) {
            $count += count($result['references']);
            return $count;
        }, 0);

        $riskyCount = array_reduce($references, function ($count, $result) {
            if (!isset($result['risky_references'])) {
                return $count;
            }
            $count += count($result['risky_references']);
            return $count;
        }, 0);

        $risky = '';
        if ($riskyCount > 0) {
            $risky = sprintf(' (%s risky references not listed)', $riskyCount);
        }

        return EchoResponse::fromMessage(sprintf(
            '%s %s literal references to %s "%s" using FS "%s"%s',
            $action,
            $count,
            $symbolContext->symbol()->symbolType(),
            $symbolContext->symbol()->name(),
            $filesystem,
            $risky
        ));
    }

    private function defaultReplacement(SymbolContext $symbolContext)
    {
        if ($symbolContext->symbol()->symbolType() === Symbol::CLASS_) {
            return (string) $symbolContext->type()->className();
        }

        return $symbolContext->symbol()->name();
    }
}
