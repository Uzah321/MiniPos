<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsProtectedCredentialsFile;
use App\Exceptions\PlatformAdminBootstrapException;
use App\Services\Auth\SuperAdminService;
use App\Services\Provisioning\PlatformAdministratorBootstrapService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BootstrapPlatformAdministrator extends Command
{
    use ReadsProtectedCredentialsFile;

    protected $signature = 'servenest:bootstrap-platform-admin
        {credentials : Absolute path to the installer-created protected JSON credentials file}
        {--rotate-existing : Rotate the password of the previously bootstrapped platform administrator}
        {--ensure : Create the administrator if missing, otherwise set its credentials again (retried installations)}';

    protected $description = 'Create the first platform administrator and operator company on a new Central Back Office';

    public function handle(PlatformAdministratorBootstrapService $bootstrap, SuperAdminService $superAdmins): int
    {
        try {
            $credentials = $this->readCredentialsFile((string) $this->argument('credentials'));
            $result = $bootstrap->bootstrap(
                $credentials,
                (bool) $this->option('rotate-existing'),
                (bool) $this->option('ensure')
            );
            unset($credentials);

            // The password is deliberately never written to stdout, stderr,
            // application logs, or command history.
            $this->info($result['created']
                ? 'Platform administrator created successfully.'
                : 'Platform administrator credentials rotated successfully.');
            $this->table(['Property', 'Value'], [
                ['Country ID', $result['country_id']],
                ['Company ID', $result['company_id']],
                ['Head-office branch ID', $result['branch_id']],
                ['Administrator ID', $result['user_id']],
            ]);

            if (! in_array($result['user_id'], $superAdmins->platformAdministratorIds(), true)) {
                $this->warn('Add the administrator ID to PLATFORM_ADMIN_POS_USER_IDS and refresh the configuration cache before signing in.');
            }

            return self::SUCCESS;
        } catch (PlatformAdminBootstrapException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Platform administrator bootstrap failed. Review the protected application log for diagnostics.');

            return self::FAILURE;
        }
    }

    protected function credentialsFileException(string $message): RuntimeException
    {
        return new PlatformAdminBootstrapException($message);
    }
}
