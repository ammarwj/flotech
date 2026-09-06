<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\DomainService;
use Illuminate\Console\Command;

/**
 * Keep the nginx config and the certificates in step with the database.
 *
 * Two jobs the admin panel cannot do alone. Activation is one click, but a
 * domain whose DNS has not propagated yet fails then and would stay failed
 * forever without a retry — and the generated config lives on a host directory
 * that only the `scheduler` container mounts, so an admin acting through the
 * `api` container never wrote it in the first place.
 *
 * Runs every minute and is a single query when there is nothing to do.
 */
class SyncCustomDomains extends Command
{
    protected $signature = 'domains:sync {--dry-run}';

    protected $description = 'Terbitkan sertifikat custom domain yang tertunda dan regenerate config nginx.';

    public function handle(DomainService $domains): int
    {
        $pending = $domains->pending();

        foreach ($pending as $event) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] {$event->custom_domain} (event #{$event->id})");

                continue;
            }

            $this->line("Aktivasi {$event->custom_domain} …");

            // issue() records its own outcome on the row; a failure here is the
            // ordinary case (DNS not ready) and must not stop the other domains.
            $domains->issue($event)
                ? $this->info("  terbit: {$event->custom_domain}")
                : $this->warn("  gagal: {$event->refresh()->domain_error}");
        }

        if (! $this->option('dry-run')) {
            // Also runs when nothing was pending: this is what repairs the config
            // after a fresh deploy wiped the host file, and publish() is a no-op
            // when the rendered config already matches what is on disk.
            $domains->publish();
        }

        $active = Event::whereNotNull('domain_certified_at')->count();
        $this->info("Selesai. {$pending->count()} tertunda diproses, {$active} domain aktif.");

        return self::SUCCESS;
    }
}
