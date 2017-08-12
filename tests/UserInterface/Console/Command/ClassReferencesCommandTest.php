<?php

namespace Phpactor\Tests\UserInterface\Console\Command;

use Phpactor\Tests\UserInterface\SystemTestCase;

class ClassReferencesCommandTest extends SystemTestCase
{
    public function setUp()
    {
        $this->initWorkspace();
        $this->loadProject('Animals');
    }

    /**
     * @testdox It should show all references to Badger
     */
    public function testReferences()
    {
        $process = $this->phpactor('class:references "Animals\Badger"');
        $this->assertSuccess($process);
        $this->assertContains('class Badger', $process->getOutput());
    }

    /**
     * @testdox It should accept a format
     */
    public function testReferencesFormatted()
    {
        $process = $this->phpactor('class:references "Animals\Badger" --format=json');
        $this->assertSuccess($process);
        $this->assertContains('"line":"class Badger', $process->getOutput());
    }

    /**
     * @testdox It should replace class references
     */
    public function testReferencesReplace()
    {
        $process = $this->phpactor('class:references "Animals\Badger" --replace="Kangaroo"');
        $this->assertSuccess($process);
        $this->assertContains('class Kangaroo', $process->getOutput());
        $this->assertContains('class Kangaroo', file_get_contents(
            $this->workspaceDir() . '/lib/Badger.php'
        ));
    }

    /**
     * @testdox It should replace class references
     */
    public function testReferencesReplaceDryRun()
    {
        $process = $this->phpactor('class:references "Animals\Badger" --dry-run --replace="Kangaroo"');
        $this->assertSuccess($process);
        $this->assertContains('class Kangaroo', $process->getOutput());
        $this->assertNotContains('class Kangaroo', file_get_contents(
            $this->workspaceDir() . '/lib/Badger.php'
        ));
    }
}

