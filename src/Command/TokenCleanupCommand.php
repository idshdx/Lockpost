<?php

namespace App\Command;

use App\Service\TokenStateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes expired entries from the stateful token store.
 *
 * The store (var/data/tokens.json) grows with every generated link and is
 * otherwise never trimmed, so this is intended to run periodically (cron /
 * systemd timer) to keep it bounded.
 */
#[AsCommand(
    name: 'app:tokens:cleanup',
    description: 'Remove expired tokens from the stateful token store.',
)]
class TokenCleanupCommand extends Command
{
    public function __construct(private readonly TokenStateService $tokenStateService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->tokenStateService->isEnabled()) {
            $io->note('Stateful token tracking is disabled (APP_TOKEN_STATEFUL=0); nothing to clean up.');

            return Command::SUCCESS;
        }

        $removed = $this->tokenStateService->cleanupExpired();

        $io->success(sprintf('Removed %d expired token(s).', $removed));

        return Command::SUCCESS;
    }
}
