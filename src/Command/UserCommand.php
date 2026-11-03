<?php

declare(strict_types=1);

namespace App\Command;

use App\Identity\Identity;
use App\Shared\Id;
use App\Shared\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'app:user:create', description: 'Create an account; clinic admins can add its email as a membership')]
final class UserCommand extends Command
{
    public function __construct(private Store $s, private Identity $identity)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('first-name', InputArgument::REQUIRED)->addArgument('last-name', InputArgument::REQUIRED)->addOption('platform-admin', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = strtolower($input->getArgument('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('Invalid email');

            return Command::FAILURE;
        }
        $question = (new Question('Password (12+ characters): '))->setHidden(true);
        $password = (new \Symfony\Component\Console\Helper\QuestionHelper())->ask($input, $output, $question);
        $this->s->db->insert('users', ['id' => Id::new(), 'email' => $email, 'first_name' => $input->getArgument('first-name'), 'last_name' => $input->getArgument('last-name'), 'password_hash' => $this->identity->hash($password), 'platform_admin' => $input->getOption('platform-admin') ? 1 : 0]);
        $output->writeln('Account created.');

        return Command::SUCCESS;
    }
}
