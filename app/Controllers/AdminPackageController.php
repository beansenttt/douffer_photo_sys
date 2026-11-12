<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Validator;
use App\Models\Package;

/**
 * Screen A15: Packages & Services Catalog (/admin/packages, Owner only).
 * Manages Photo & Video coverage packages, Other inquire-only services, and Add-ons.
 */
final class AdminPackageController
{
    private const CATEGORIES = [
        'photo'       => 'Photography',
        'video'       => 'Videography',
        'photo_video' => 'Photo + Video',
        'other'       => 'Other service (Inquire only)',
    ];

    /**
     * GET /admin/packages
     */
    public function index(): void
    {
        $tab = trim((string) ($_GET['tab'] ?? 'packages'));
        if (!in_array($tab, ['packages', 'other', 'addons'], true)) {
            $tab = 'packages';
        }

        $allPackages = Package::allForAdmin();
        $photoVideoPackages = array_values(array_filter($allPackages, static fn(array $p): bool => $p['category'] !== 'other'));
        $otherServices = array_values(array_filter($allPackages, static fn(array $p): bool => $p['category'] === 'other'));
        $addons = Package::allAddons();

        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $editingPackage = $editId > 0 ? Package::findById($editId) : null;

        view('admin/packages', [
            'title'              => 'Packages & Services',
            'screen'             => 'A15',
            'back'               => '/admin/more',
            'tab'                => $tab,
            'categories'         => self::CATEGORIES,
            'photoVideoPackages' => $photoVideoPackages,
            'otherServices'      => $otherServices,
            'addons'             => $addons,
            'editingPackage'     => $editingPackage,
        ], 'admin');
    }

    /**
     * POST /admin/packages — Create a new package or service (or update if id is given).
     */
    public function store(): void
    {
        if (!empty($_POST['id'])) {
            $this->update((string) $_POST['id']);
            return;
        }
        $this->savePackage(null);
    }

    /**
     * POST /admin/packages/{id} — Update an existing package or service.
     */
    public function update(string $id): void
    {
        $pkgId = (int) $id;
        $existing = Package::findById($pkgId);
        if (!$existing) {
            throw new HttpException(404, 'Package not found.');
        }
        $this->savePackage($pkgId);
    }

    /**
     * POST /admin/packages/{id}/toggle — Activate or deactivate a package/service.
     */
    public function toggle(string $id): void
    {
        $pkgId = (int) $id;
        $pkg = Package::findById($pkgId);
        if (!$pkg) {
            throw new HttpException(404, 'Package not found.');
        }

        $newActive = (int) $pkg['is_active'] === 1 ? 0 : 1;
        DB::update('packages', [
            'is_active'  => $newActive,
            'updated_at' => Clock::now(),
        ], 'id = ?', [$pkgId]);

        Audit::log('package_saved', 'package', $pkgId, [
            'name'      => $pkg['name'],
            'category'  => $pkg['category'],
            'is_active' => $newActive,
        ]);

        Flash::set('success', $newActive === 1 ? 'Package activated ✓' : 'Package deactivated ✓');
        $tab = $pkg['category'] === 'other' ? 'other' : 'packages';
        redirect('/admin/packages?tab=' . $tab);
    }

    /**
     * POST /admin/packages/addons — Create a new add-on service.
     */
    public function storeAddon(): void
    {
        $errors = Validator::make($_POST, [
            'name'  => 'required|max:100',
            'price' => 'required|money|min_value:0',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please enter a valid add-on name and price.');
            redirect('/admin/packages?tab=addons');
        }

        $name = trim((string) $_POST['name']);
        $price = round((float) str_replace(',', '', (string) $_POST['price']), 2);
        $isActive = isset($_POST['is_active']) ? ((int) $_POST['is_active'] === 1 ? 1 : 0) : 1;

        $addonId = DB::insert('addons', [
            'name'      => $name,
            'price'     => $price,
            'is_active' => $isActive,
        ]);

        Audit::log('package_saved', 'addon', $addonId, [
            'name'      => $name,
            'price'     => $price,
            'is_active' => $isActive,
        ]);

        Flash::set('success', 'Add-on service saved ✓');
        redirect('/admin/packages?tab=addons');
    }

    /**
     * POST /admin/packages/addons/{id} — Update an existing add-on service.
     */
    public function updateAddon(string $id): void
    {
        $addonId = (int) $id;
        $addon = Package::findAddonById($addonId);
        if (!$addon) {
            throw new HttpException(404, 'Add-on not found.');
        }

        $errors = Validator::make($_POST, [
            'name'  => 'required|max:100',
            'price' => 'required|money|min_value:0',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please enter a valid add-on name and price.');
            redirect('/admin/packages?tab=addons');
        }

        $name = trim((string) $_POST['name']);
        $price = round((float) str_replace(',', '', (string) $_POST['price']), 2);
        $isActive = isset($_POST['is_active']) ? ((int) $_POST['is_active'] === 1 ? 1 : 0) : (int) $addon['is_active'];

        DB::update('addons', [
            'name'      => $name,
            'price'     => $price,
            'is_active' => $isActive,
        ], 'id = ?', [$addonId]);

        Audit::log('package_saved', 'addon', $addonId, [
            'name'      => $name,
            'price'     => $price,
            'is_active' => $isActive,
        ]);

        Flash::set('success', 'Add-on updated ✓');
        redirect('/admin/packages?tab=addons');
    }

    /**
     * POST /admin/packages/addons/{id}/toggle — Activate or deactivate an add-on.
     */
    public function toggleAddon(string $id): void
    {
        $addonId = (int) $id;
        $addon = Package::findAddonById($addonId);
        if (!$addon) {
            throw new HttpException(404, 'Add-on not found.');
        }

        $newActive = (int) $addon['is_active'] === 1 ? 0 : 1;
        DB::update('addons', ['is_active' => $newActive], 'id = ?', [$addonId]);

        Audit::log('package_saved', 'addon', $addonId, [
            'name'      => $addon['name'],
            'is_active' => $newActive,
        ]);

        Flash::set('success', $newActive === 1 ? 'Add-on activated ✓' : 'Add-on deactivated ✓');
        redirect('/admin/packages?tab=addons');
    }

    private function savePackage(?int $pkgId): void
    {
        $category = (string) ($_POST['category'] ?? 'photo');
        $rules = [
            'name'                  => 'required|max:100',
            'category'              => 'required|in:photo,video,photo_video,other',
            'description'           => 'max:255',
            'inclusions'            => 'max:4000',
            'price'                 => $category === 'other' ? 'money|min_value:0' : 'required|money|min_value:1',
            'down_payment_override' => 'money|min_value:0',
            'sort_order'            => 'integer',
        ];

        $errors = Validator::make($_POST, $rules);
        $rawPrice = trim((string) ($_POST['price'] ?? ''));
        $rawDp = trim((string) ($_POST['down_payment_override'] ?? ''));

        $price = $rawPrice !== '' ? round((float) str_replace(',', '', $rawPrice), 2) : null;
        $dpOverride = ($rawDp !== '' && (float) str_replace(',', '', $rawDp) > 0)
            ? round((float) str_replace(',', '', $rawDp), 2)
            : null;

        if ($price !== null && $dpOverride !== null && $dpOverride > $price) {
            $errors['down_payment_override'] = 'The down payment override cannot be higher than the package price.';
        }

        $tab = $category === 'other' ? 'other' : 'packages';

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please fix the highlighted package fields.');
            redirect('/admin/packages?tab=' . $tab . ($pkgId ? '&edit=' . $pkgId : ''));
        }

        $name = trim((string) $_POST['name']);
        $description = trim((string) ($_POST['description'] ?? ''));
        $inclusions = trim((string) ($_POST['inclusions'] ?? ''));
        $sortOrder = trim((string) ($_POST['sort_order'] ?? '')) !== '' ? (int) $_POST['sort_order'] : ($category === 'other' ? 10 : 1);
        $isActive = isset($_POST['is_active']) ? ((int) $_POST['is_active'] === 1 ? 1 : 0) : 1;
        $now = Clock::now();

        $payload = [
            'name'                  => $name,
            'category'              => $category,
            'description'           => $description !== '' ? $description : null,
            'inclusions'            => $inclusions !== '' ? $inclusions : null,
            'price'                 => $price,
            'down_payment_override' => $dpOverride,
            'is_active'             => $isActive,
            'sort_order'            => $sortOrder,
        ];

        if ($pkgId !== null) {
            $payload['updated_at'] = $now;
            DB::update('packages', $payload, 'id = ?', [$pkgId]);
            $savedId = $pkgId;
        } else {
            $payload['created_at'] = $now;
            $savedId = DB::insert('packages', $payload);
        }

        Audit::log('package_saved', 'package', $savedId, [
            'name'                  => $name,
            'category'              => $category,
            'price'                 => $price,
            'down_payment_override' => $dpOverride,
            'is_active'             => $isActive,
        ]);

        Flash::set('success', $pkgId !== null ? 'Package updated ✓' : 'Package added ✓');
        redirect('/admin/packages?tab=' . $tab);
    }
}
