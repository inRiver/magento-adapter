<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Console\Command;

use Inriver\Adapter\Cron\Cleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends Command
{
    private Cleanup $cleanup;

    /**
     * @param Cleanup $cleanup
     * @param string|null $name
     */
    public function __construct(Cleanup $cleanup, ?string $name = null)
    {
        parent::__construct($name);
        $this->cleanup = $cleanup;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('inriver:adapter:cleanup')
            ->setDescription('cleanup old import files');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cleanup->execute();
    }
}
