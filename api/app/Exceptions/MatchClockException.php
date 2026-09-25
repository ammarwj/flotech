<?php

namespace App\Exceptions;

use Exception;

/**
 * Jam pertandingan tidak bisa digeser seperti yang diminta — cabang tanpa jam,
 * tie beregu yang tidak punya babak, laga yang sudah selesai, atau babak yang
 * sudah habis.
 *
 * Dirender sebagai ApiResponse di bootstrap/app.php supaya `MatchClockService`
 * bebas dari urusan HTTP — pola yang sama dengan `MatchResultException`. Itulah
 * yang membuat **kedua pintu** yang menggerakkan jam (organizer dan petugas
 * pertandingan) menolak dengan kalimat yang sama alih-alih masing-masing
 * menyimpan salinan kata-katanya sendiri.
 */
class MatchClockException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $errors
     */
    public function __construct(string $message, protected ?array $errors = null, protected int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }
}
