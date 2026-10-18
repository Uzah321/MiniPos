<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsProtectedCredentialsFile;
use App\Exceptions\StandaloneAdminBootstrapException;
use App\Services\Provisioning\StandaloneInitialAdminBootstrapService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BootstrapStandaloneInitialAdmin extends Command
{
    use ReadsProtectedCredentialsFile;

    protected $signature = 'salexa:standalone-bootstrap-admin
        {credentials : Absolute path to the installer-created protected JSON credentials file}
        {--rotate-existing : Rotate the previously designated initial administrator before other users exist}';

    protected $description = 'Create the first company-scoped administrator on a freshly provisioned standalone installation';

    public function handle(StandaloneInitialAdminBootstrapService $bootstrap): int
    {
        try {
            $credentials = $this->readCredentialsFile((string) $this->argument('credentials'));
            $result = $bootstrap->bootstrap($credentials, (bool) $this->option('rotate-existing'));

            // The password and optional login code are deliberately never
            // written to stdout, stderr, application logs, or command history.
            $this->info($result['created']
                ? 'Initial standalone administrator created successfully.'
                : 'Initial standalone administrator credentials rotated successfully.');
            $this->table(['Property', 'Value'], [
                ['Company ID', $result['company_id']],
                ['Default branch ID', $result['branch_id']],
                ['Administrator ID', $result['user_id']],
            ]);

            unset($credentials);

            return self::SUCCESS;
        } catch (StandaloneAdminBootstrapException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Initial administrator bootstrap failed. Review the protected application log for diagnostics.');

            return self::FAILURE;
        }
    }

    protected function credentialsFileException(string $message): RuntimeException
    {
        return new StandaloneAdminBootstrapException($message);
    }
}
