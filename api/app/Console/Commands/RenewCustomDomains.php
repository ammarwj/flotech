<?php

namespace App\Console\Commands;

use App\Services\DomainService;
use Illuminate\Console\Command;

/**
 * Renew the custom-domain certificates.
 *
 * Let's Encrypt certificates last 90 days and certbot only renews within 30 of
 * expiry, so this is a no-op on almost every run — which is exactly why it has
 * to be scheduled rather than triggered: nobody notices a certificate until the
 * day it stops working.
 *
 * The usual `certbot renew` cron cannot be used: certbot's state lives on a
 * shared mount that only this container knows the path to (see
 * DomainService::certbotCommand), and nginx has to be told to re-read the files
 * afterwards.
 */
class RenewCustomDomains extends Command
{
    protected $signature = 'domains:renew';

    protected $description = 'Perpanjang sertifikat SSL custom domain yang mendekati kedaluwarsa.';

    public function handle(DomainService $domains): int
    {
        if (! $domains->renew()) {
            $this->error('certbot renew gagal. Cek log di direktori letsencrypt.');

            return self::FAILURE;
        }

        $this->info('certbot renew selesai.');

        return self::SUCCESS;
    }
}
