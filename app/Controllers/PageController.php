<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Settings;

/** Static public pages. */
final class PageController
{
    /** Data Privacy Act of 2012 (RA 10173) privacy notice. */
    public function privacy(): void
    {
        view('pages/privacy', [
            'title'  => 'Privacy notice',
            'screen' => 'PRIVACY',
            'back'   => '/',
            'studio' => Settings::get('studio_name'),
            'phone'  => Settings::get('studio_phone'),
            'address'=> Settings::get('studio_address'),
        ], 'client');
    }
}
