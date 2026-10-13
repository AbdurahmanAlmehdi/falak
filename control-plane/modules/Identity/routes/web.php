<?php

use Falak\Identity\Http\Controllers\Auth\AuthenticatedSessionController;
use Falak\Identity\Http\Controllers\Auth\ConfirmablePasswordController;
use Falak\Identity\Http\Controllers\Auth\EmailVerificationNotificationController;
use Falak\Identity\Http\Controllers\Auth\EmailVerificationPromptController;
use Falak\Identity\Http\Controllers\Auth\NewPasswordController;
use Falak\Identity\Http\Controllers\Auth\PasswordResetLinkController;
use Falak\Identity\Http\Controllers\Auth\RegisteredUserController;
use Falak\Identity\Http\Controllers\Auth\TwoFactorChallengeController;
use Falak\Identity\Http\Controllers\Auth\VerifyEmailController;
use Falak\Identity\Http\Controllers\Organizations\AuditLogController;
use Falak\Identity\Http\Controllers\Organizations\InvitationController;
use Falak\Identity\Http\Controllers\Organizations\MemberController;
use Falak\Identity\Http\Controllers\Organizations\OrganizationController;
use Falak\Identity\Http\Controllers\Organizations\TeamController;
use Falak\Identity\Http\Controllers\Settings\ApiTokenController;
use Falak\Identity\Http\Controllers\Settings\CliAuthorizeController;
use Falak\Identity\Http\Controllers\Settings\PasswordController;
use Falak\Identity\Http\Controllers\Settings\ProfileController;
use Falak\Identity\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // `falak login` in the browser. Approving mints a token, so it needs the same fresh password (+ 2FA) check as
    // Settings → API tokens; the code is in the path, so the confirmation comes back to this page.
    Route::get('cli/authorize', [CliAuthorizeController::class, 'show'])->name('cli.authorize');
    Route::post('cli/authorize', [CliAuthorizeController::class, 'lookup'])->middleware('throttle:20,1')->name('cli.authorize.lookup');
    Route::get('cli/authorize/{code}', [CliAuthorizeController::class, 'show'])->name('cli.authorize.show');
    Route::post('cli/authorize/{code}', [CliAuthorizeController::class, 'approve'])->middleware(['reauthenticated', 'throttle:20,1'])->name('cli.authorize.approve');
    Route::post('cli/authorize/{code}/deny', [CliAuthorizeController::class, 'deny'])->middleware('throttle:20,1')->name('cli.authorize.deny');

    // Personal settings
    Route::redirect('settings', 'settings/profile');
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
    Route::get('settings/appearance', fn () => Inertia::render('Identity/settings/appearance'))->name('appearance');

    Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::middleware('password.confirm')->group(function () {
        Route::post('settings/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.enable');
        Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
        Route::post('settings/two-factor/reveal', [TwoFactorController::class, 'reveal'])->name('two-factor.reveal');
        Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.disable');
    });
    Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');

    // Organizations
    Route::get('organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::put('organizations/current', [OrganizationController::class, 'switch'])->name('organizations.switch');

    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::middleware('org')->group(function () {
        Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        // A token outlives the session and may carry secrets.reveal: minting one needs a fresh password (+ 2FA) check.
        Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->middleware('reauthenticated')->name('api-tokens.store');
        Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

        // Canonical organization settings pages live in the /settings/{section} shell (docs/UI_DESIGN.md §3).
        Route::get('settings/organization', [OrganizationController::class, 'edit'])->name('organization.settings');
        Route::get('settings/members', [MemberController::class, 'index'])->name('organization.members.index');
        Route::get('settings/teams', [TeamController::class, 'index'])->name('organization.teams.index');
        Route::get('settings/audit-log', [AuditLogController::class, 'index'])->name('organization.audit-log');

        // Legacy URLs keep working (bookmarks, alert links): permanent redirect, query string preserved.
        foreach (['settings' => 'organization', 'members' => 'members', 'teams' => 'teams', 'audit-log' => 'audit-log'] as $legacy => $section) {
            Route::get("organization/{$legacy}", fn (Request $request) => redirect()->to(
                "/settings/{$section}".($request->getQueryString() ? '?'.$request->getQueryString() : ''),
                301,
            ))->name("organization.legacy.{$legacy}");
        }

        Route::prefix('organization')->name('organization.')->group(function () {
            Route::patch('/', [OrganizationController::class, 'update'])->name('update');
            Route::delete('/', [OrganizationController::class, 'destroy'])->name('destroy');
            Route::post('transfer', [OrganizationController::class, 'transfer'])->name('transfer');

            Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

            Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

            Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
            Route::patch('teams/{team}', [TeamController::class, 'update'])->name('teams.update');
            Route::put('teams/{team}/members', [TeamController::class, 'members'])->name('teams.members');
            Route::delete('teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        });
    });
});
