<?php

namespace App\Command;

use App\Message\Command\Migrate\Batch\MigrateUserToZitadelBatchSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Command to migrate users to Zitadel.
 */
#[AsCommand(name: 'app:migrate-user:to:zitadel', description: 'Migrate users to Zitadel.')]
final class MigrateUserToZitadelCommand extends Command
{
    /**
     * The message bus used to dispatch migration commands.
     */
    private MessageBusInterface $commandBus;

    /**
     * @param MessageBusInterface $commandBus The message bus used to dispatch migration commands.
     */
    public function __construct(MessageBusInterface $commandBus)
    {
        parent::__construct();
        $this->commandBus = $commandBus;
    }

    /**
     * Execute the migration command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dispatchMigrationCommand();

        return Command::SUCCESS;
    }

    /**
     * Dispatch the batch migration message onto the command bus.
     *
     * @return void
     */
    private function dispatchMigrationCommand(): void
    {
        $this->commandBus->dispatch(new MigrateUserToZitadelBatchSync());
    }
}
