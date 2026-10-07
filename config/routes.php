<?php

declare(strict_types=1);

use App\Router;

return static function (Router $router): void {
    $router->addRoute('GET', '/', 'DashboardController@index');

    $router->addRoute('GET',  '/login',     'AuthController@showLogin');
    $router->addRoute('POST', '/login',     'AuthController@login');
    $router->addRoute('GET',  '/register',  'AuthController@showRegister');
    $router->addRoute('POST', '/register',  'AuthController@register');
    $router->addRoute('GET',  '/verify-email/{token}', 'AuthController@verifyEmail');
    $router->addRoute('GET',  '/logout',    'AuthController@logout');
    $router->addRoute('GET',  '/forgot-password', 'AuthController@showForgot');
    $router->addRoute('POST', '/forgot-password', 'AuthController@forgot');
    $router->addRoute('GET',  '/reset-password/{token:[a-f0-9]+}', 'AuthController@showReset');
    $router->addRoute('POST', '/reset-password/{token:[a-f0-9]+}', 'AuthController@reset');
    $router->addRoute('GET',  '/2fa',       'AuthController@show2fa');
    $router->addRoute('POST', '/2fa',       'AuthController@verify2fa');

    // Account security settings (2FA, sessions)
    $router->addRoute('GET',  '/settings/security',                       'SecurityController@index');
    $router->addRoute('POST', '/settings/security/2fa/enable',            'SecurityController@enable2fa');
    $router->addRoute('POST', '/settings/security/2fa/confirm',           'SecurityController@confirm2fa');
    $router->addRoute('POST', '/settings/security/2fa/disable',           'SecurityController@disable2fa');
    $router->addRoute('POST', '/settings/security/sessions/revoke/{token:[a-f0-9]+}', 'SecurityController@revokeSession');
    $router->addRoute('POST', '/settings/security/sessions/revoke-others','SecurityController@revokeOthers');

    // Search
    $router->addRoute('GET',  '/search',    'SearchController@search');
    $router->addRoute('GET',  '/api/search/suggest', 'SearchController@suggest');

    // Account: own repositories, API tokens, public profiles
    $router->addRoute('GET',  '/repos/new',          'AccountController@newRepoForm');
    $router->addRoute('POST', '/repos/new',          'AccountController@newRepoStore');
    $router->addRoute('GET',  '/repos/import',                'RepoImportController@form');
    $router->addRoute('POST', '/repos/import',                'RepoImportController@import');
    $router->addRoute('GET',  '/repos/import/lookup',         'RepoImportController@lookup');
    $router->addRoute('GET',  '/repos/import/progress',       'RepoImportController@progress');
    $router->addRoute('GET',  '/repos/import/run',            'RepoImportController@run');
    // Personal Access Tokens (PAT)
    $router->addRoute('GET',  '/settings/tokens',                        'AccountController@tokensIndex');
    $router->addRoute('GET',  '/settings/tokens/pat',                    'AccountController@tokensIndex');
    $router->addRoute('POST', '/settings/tokens',                        'AccountController@tokensCreate');
    $router->addRoute('POST', '/settings/tokens/revoke/{id:\d+}',        'AccountController@tokensRevoke');
    $router->addRoute('POST', '/settings/tokens/{id:\d+}/revoke',        'AccountController@tokensRevoke');
    $router->addRoute('POST', '/settings/tokens/{id:\d+}/delete',        'AccountController@tokensDelete');
    $router->addRoute('POST', '/settings/tokens/delete/{id:\d+}',        'AccountController@tokensDelete');
        $router->addRoute('GET',  '/settings',                        'AccountController@accountSettings');
    $router->addRoute('GET',  '/settings/account',                'AccountController@accountSettings');
    $router->addRoute('GET',  '/settings/keys',                   'AccountController@accountSettings');
    $router->addRoute('GET',  '/settings/updates',                  'AccountController@accountSettings');
    $router->addRoute('POST', '/settings/updates/regenerate-token', 'AccountController@regenerateUpdateToken');

    $router->addRoute('POST', '/settings/keys',                   'AccountController@sshKeyAdd');
    $router->addRoute('POST', '/settings/keys/{id:\d+}/delete',        'AccountController@sshKeyDelete');
    $router->addRoute('POST', '/settings/account/profile',        'AccountController@updateProfile');
    $router->addRoute('POST', '/settings/account/password',       'AccountController@changePassword');
    $router->addRoute('POST', '/settings/account/email',          'AccountController@changeEmail');
    $router->addRoute('POST', '/settings/account/delete',         'AccountController@deleteAccount');
    $router->addRoute('GET',  '/u/{username:[a-zA-Z0-9._-]+}', 'AccountController@profile');
    $router->addRoute('POST', '/u/{username:[a-zA-Z0-9._-]+}/follow', 'SocialController@toggleFollow');

    // Mobile App OTP Pairing & Authentication API
    $router->addRoute('POST', '/api/v1/auth/mobile-otp/generate',                        'MobileOtpController@generate');
    $router->addRoute('POST', '/api/v1/auth/mobile-otp/verify',                          'MobileOtpController@verify');

    // REST API v1 (Bearer token auth)
    $router->addRoute('GET', '/api/v1/user',                    'ApiController@whoami');
    // REST API v1 (Branch & Repo queries)
    $router->addRoute('GET', '/api/v1/repos/{slug}/branches/{branch:.+}',            'ApiController@singleBranch');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}/branches/{branch:.+}',    'ApiController@ownerRepoSingleBranch');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}/branches',               'ApiController@ownerRepoBranches');
    $router->addRoute('GET', '/api/v1/repos/{slug}/branches',                        'ApiController@branches');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}/commits',                'ApiController@ownerRepoCommits');
    $router->addRoute('GET', '/api/v1/repos/{slug}/commits',                        'ApiController@commits');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}/tags',                   'ApiController@ownerRepoTags');
    $router->addRoute('GET', '/api/v1/repos/{slug}/tags',                           'ApiController@tags');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}/tree',                   'ApiController@ownerRepoTree');
    $router->addRoute('GET', '/api/v1/repos/{slug}/tree',                           'ApiController@tree');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}/blob',                   'ApiController@ownerRepoBlob');
    $router->addRoute('GET', '/api/v1/repos/{slug}/blob',                           'ApiController@blob');
    $router->addRoute('GET', '/api/v1/repos/{slug}/issues',                         'ApiController@issues');
    $router->addRoute('GET', '/api/v1/repos/{slug}/pulls',                          'ApiController@pulls');
    $router->addRoute('GET', '/api/v1/repos/{owner}/{repo}',                       'ApiController@ownerRepo');
    $router->addRoute('GET', '/api/v1/repos/{slug}',                               'ApiController@repo');
    $router->addRoute('POST', '/api/v1/repos',                   'ApiController@createRepo');
    $router->addRoute('POST', '/api/v1/user/repos',              'ApiController@createRepo');
    $router->addRoute('PATCH', '/api/v1/repos/{slug:[a-zA-Z0-9._-]+}', 'ApiController@updateRepo');
    $router->addRoute('DELETE', '/api/v1/repos/{slug:[a-zA-Z0-9._-]+}', 'ApiController@deleteRepo');

    // REST API v1 — Archives, Bundles, Restore & Sync
    $router->addRoute('GET',  '/api/v1/repos/{owner}/{repo}/archive/{ref:.+}.zip',    'ApiController@archiveZip');
    $router->addRoute('GET',  '/api/v1/repos/{owner}/{repo}/archive/{ref:.+}.tar.gz', 'ApiController@archiveTarGz');
    $router->addRoute('GET',  '/api/v1/repos/{owner}/{repo}/bundle',                  'ApiController@bundle');
    $router->addRoute('POST', '/api/v1/repos/restore',                               'ApiController@restoreRepo');
    $router->addRoute('POST', '/api/v1/repos/{owner}/{repo}/sync',                   'ApiController@syncRemote');

    // REST API v1 — Branches & Tags Management
    $router->addRoute('POST',   '/api/v1/repos/{owner}/{repo}/branches',             'ApiController@createBranch');
    $router->addRoute('DELETE', '/api/v1/repos/{owner}/{repo}/branches/{branch:.+}', 'ApiController@deleteBranch');
    $router->addRoute('PUT',    '/api/v1/repos/{owner}/{repo}/default-branch',       'ApiController@setDefaultBranch');
    $router->addRoute('POST',   '/api/v1/repos/{owner}/{repo}/tags',                 'ApiController@createTag');
    $router->addRoute('DELETE', '/api/v1/repos/{owner}/{repo}/tags/{tag:.+}',        'ApiController@deleteTag');

    // Releases API (read + write, per-repo collaborator enforced)
    $router->addRoute('GET',    '/api/v1/repos/{owner}/{repo}/releases',                     'ApiController@listReleases');
    $router->addRoute('POST',   '/api/v1/repos/{owner}/{repo}/releases',                     'ApiController@createRelease');
    $router->addRoute('PATCH',  '/api/v1/repos/{owner}/{repo}/releases/{id:\d+}',             'ApiController@editRelease');
    $router->addRoute('DELETE', '/api/v1/repos/{owner}/{repo}/releases/{id:\d+}',             'ApiController@deleteRelease');
    $router->addRoute('GET',    '/api/v1/repos/{owner}/{repo}/releases/{id:\d+}/assets',      'ApiController@listReleaseAssets');

    // REST API v1 — Raw Content & Direct Commit API
    $router->addRoute('GET',  '/raw/{owner}/{repo}/{ref}/{path:.*}',                 'ApiController@rawContent');
    $router->addRoute('GET',  '/api/v1/repos/{owner}/{repo}/raw',                    'ApiController@rawContent');
    $router->addRoute('POST', '/api/v1/repos/{owner}/{repo}/contents/{path:.*}',     'ApiController@directCommit');

    // REST API v1 — Issues & Pull Requests Write API
    $router->addRoute('POST',  '/api/v1/repos/{owner}/{repo}/issues',               'ApiController@createIssue');
    $router->addRoute('PATCH', '/api/v1/repos/{owner}/{repo}/issues/{id:\d+}',      'ApiController@updateIssue');
    $router->addRoute('POST',  '/api/v1/repos/{owner}/{repo}/pulls',                'ApiController@createPull');
    $router->addRoute('POST',  '/api/v1/repos/{owner}/{repo}/pulls/{id:\d+}/merge',  'ApiController@mergePull');

    // License Verification REST API (Public Endpoint)
    $router->addRoute('GET',  '/api/v1/license/check',                           'LicenseAdminController@apiCheck');
    $router->addRoute('POST', '/api/v1/license/check',                           'LicenseAdminController@apiCheck');
    $router->addRoute('POST', '/api/v1/admin/license/generate',                  'LicenseAdminController@apiGenerate');
    $router->addRoute('POST', '/api/v1/license/activate',                        'LicenseAdminController@apiActivate');
    $router->addRoute('POST', '/api/v1/license/heartbeat',                       'LicenseAdminController@apiHeartbeat');
    $router->addRoute('GET',  '/api/v1/admin/license/devices',                      'LicenseAdminController@apiListDevices');
    $router->addRoute('POST', '/api/v1/admin/license/device/revoke',               'LicenseAdminController@apiRevokeDevice');
    $router->addRoute('POST', '/api/v1/license/sync',                            'LicenseAdminController@apiSync');
    $router->addRoute('GET',  '/api/v1/license/sync',                            'LicenseAdminController@apiSync');
    $router->addRoute('POST', '/api/v1/admin/delete',                            'LicenseAdminController@apiAdminDelete');
    $router->addRoute('POST', '/api/v1/admin/reset-ip',                          'LicenseAdminController@apiAdminResetIp');

    // License Lifecycle Management, HWID Binding & Analytics API Suite
    $router->addRoute('POST',   '/api/v1/licenses',                                     'LicenseApiController@create');
    $router->addRoute('GET',    '/api/v1/licenses',                                     'LicenseApiController@list');
    $router->addRoute('GET',    '/api/v1/licenses/stats',                               'LicenseApiController@stats');
    $router->addRoute('GET',    '/api/v1/licenses/{id_or_key}',                         'LicenseApiController@show');
    $router->addRoute('PUT',    '/api/v1/licenses/{id_or_key}',                         'LicenseApiController@update');
    $router->addRoute('POST',   '/api/v1/licenses/{id_or_key}/status',                  'LicenseApiController@updateStatus');
    $router->addRoute('DELETE', '/api/v1/licenses/{id_or_key}',                         'LicenseApiController@delete');
    $router->addRoute('GET',    '/api/v1/licenses/{id_or_key}/devices',                 'LicenseApiController@listDevices');
    $router->addRoute('DELETE', '/api/v1/licenses/{id_or_key}/devices/{machine_id}',    'LicenseApiController@unbindDevice');
    $router->addRoute('POST',   '/api/v1/licenses/{id_or_key}/reset-bindings',         'LicenseApiController@resetBindings');

    // REST API v1 — SSH Keys API
    $router->addRoute('GET',    '/api/v1/user/keys',                                'ApiController@listSshKeys');
    $router->addRoute('POST',   '/api/v1/user/keys',                                'ApiController@addSshKey');
    $router->addRoute('DELETE', '/api/v1/user/keys/{id:\d+}',                       'ApiController@deleteSshKey');

    // Platform User Guide and Documentation
    $router->addRoute('GET',  '/docs',      'DocController@index');
    $router->addRoute('GET',  '/guide',     'DocController@index');

    // Essential site pages (must be registered before the {username} catch-all)
    $router->addRoute('GET',  '/privacy',   'PagesController@privacy');
    $router->addRoute('GET',  '/terms',     'PagesController@terms');
    $router->addRoute('GET',  '/about',     'PagesController@about');
    $router->addRoute('GET',  '/contact',   'PagesController@contact');
    $router->addRoute('GET',  '/security',  'PagesController@security');

    // Support desk — open to visitors (guests) and registered users
    $router->addRoute('GET',  '/support',                  'SupportController@dashboard');
    $router->addRoute('POST', '/support/tickets',          'SupportController@store');
    $router->addRoute('POST', '/support/track',            'SupportController@track');
    $router->addRoute('GET',  '/support/ticket/{reference:TCK-[A-Fa-f0-9]{8}}', 'SupportController@show');
    $router->addRoute('POST', '/support/ticket/{reference:TCK-[A-Fa-f0-9]{8}}/reply', 'SupportController@reply');

    // User notifications (registered accounts only)
    $router->addRoute('GET',  '/notifications',      'SocialController@notifications');
    $router->addRoute('POST', '/notifications/read', 'SocialController@markAllRead');
    $router->addRoute('GET',  '/api/v1/notifications/unread', 'SocialController@apiUnread');

$ap = '{admin_sec_prefix:cp_[a-f0-9]{12}}';

    // Stealth gateway: /admin redirects owner to dynamic session hash or returns 404 for others
    $router->addRoute('GET',  '/admin',                                          'RepoManageController@gateway');

    // Dynamic per-session admin control panel routes
    $router->addRoute('GET',  "/{$ap}",                                          'RepoManageController@index');
    $router->addRoute('GET',  "/{$ap}/repos",                                    'RepoManageController@repos');
    $router->addRoute('GET',  "/{$ap}/repos/create",                             'RepoManageController@create');
    $router->addRoute('GET',  "/{$ap}/repos/import",                             'RepoImportController@form');
    $router->addRoute('GET',  "/{$ap}/repos/import/lookup",                      'RepoImportController@lookup');
    $router->addRoute('GET',  "/{$ap}/repos/import/progress",                    'RepoImportController@progress');
    $router->addRoute('GET',  "/{$ap}/repos/import/run",                         'RepoImportController@run');
    $router->addRoute('POST', "/{$ap}/repos/import",                             'RepoImportController@import');
    $router->addRoute('POST', "/{$ap}/repos",                                    'RepoManageController@store');
    $router->addRoute('GET',  "/{$ap}/repos/{slug}/edit",                        'RepoManageController@edit');
    $router->addRoute('POST', "/{$ap}/repos/{slug}",                             'RepoManageController@update');
    $router->addRoute('POST', "/{$ap}/repos/{slug}/delete",                      'RepoManageController@delete');
    $router->addRoute('POST', "/{$ap}/repos/{slug}/sync",                        'RepoManageController@sync');
    $router->addRoute('POST', "/{$ap}/repos/{slug}/collaborators",               'RepoManageController@collaboratorsAdd');
    $router->addRoute('POST', "/{$ap}/repos/{slug}/collaborators/{id}/delete",   'RepoManageController@collaboratorsRemove');

    $router->addRoute('GET',  "/{$ap}/bug-reports",                              'IssuesController@adminIndex');
    
    // License Manager — Admin GUI & Operations
    $router->addRoute('GET',  "/{$ap}/licenses",                                 'LicenseAdminController@index');
    $router->addRoute('POST', "/{$ap}/licenses/create",                          'LicenseAdminController@create');
    $router->addRoute('POST', "/{$ap}/licenses/{id:\d+}/renew",                  'LicenseAdminController@renew');
    $router->addRoute('POST', "/{$ap}/licenses/{id:\d+}/block",                  'LicenseAdminController@toggleBlock');
    $router->addRoute('POST', "/{$ap}/licenses/{id:\d+}/delete",                 'LicenseAdminController@delete');
    $router->addRoute('POST', "/{$ap}/bug-reports/{id}/status",                  'IssuesController@adminStatus');

    // Global Limits & Quotas Policy
    $router->addRoute('GET',  "/{$ap}/limits",                                   'AdminSettingsController@limits');
    $router->addRoute('POST', "/{$ap}/limits",                                   'AdminSettingsController@updateLimits');

    $router->addRoute('GET',  "/{$ap}/settings",                                 'AdminSettingsController@settings');
    $router->addRoute('POST', "/{$ap}/settings",                                 'AdminSettingsController@updateSettings');
    $router->addRoute('POST', "/{$ap}/settings/test-cache",                      'AdminSettingsController@testCache');
    $router->addRoute('GET',  "/{$ap}/email",                                    'EmailAdminController@index');
    $router->addRoute('POST', "/{$ap}/email",                                    'EmailAdminController@save');
    $router->addRoute('POST', "/{$ap}/email/test",                               'EmailAdminController@test');
    $router->addRoute('GET',  "/{$ap}/audit-logs",                               'AdminSettingsController@auditLogs');
    $router->addRoute('GET',  "/{$ap}/downloads",                                'DownloadCenterController@adminIndex');
    $router->addRoute('GET',  "/{$ap}/downloads/reports",                        'DownloadCenterController@adminReports');
    $router->addRoute('POST', "/{$ap}/downloads/reports/{id:\d+}/status",         'DownloadCenterController@adminReportStatus');
    $router->addRoute('POST', "/{$ap}/downloads/reports/{id:\d+}/delete",         'DownloadCenterController@adminReportDelete');
    $router->addRoute('POST', "/{$ap}/downloads/upload",                         'DownloadCenterController@adminUpload');
    $router->addRoute('POST', "/{$ap}/downloads/{id:\d+}/delete",                'DownloadCenterController@adminDelete');
    // Folder management
    $router->addRoute('POST', "/{$ap}/downloads/folders/create",                 'DownloadCenterController@folderCreate');
    $router->addRoute('POST', "/{$ap}/downloads/folders/{id:\d+}/rename",        'DownloadCenterController@folderRename');
    $router->addRoute('POST', "/{$ap}/downloads/folders/{id:\d+}/delete",        'DownloadCenterController@folderDelete');
    $router->addRoute('POST', "/{$ap}/downloads/{id:\d+}/move",                  'DownloadCenterController@fileMove');
    $router->addRoute('GET',  "/{$ap}/downloads/folder/{slug:[a-z0-9_-]+}",      'DownloadCenterController@adminFolder');

    // Languages Management
    $router->addRoute('GET',  "/{$ap}/languages",                                'LanguageAdminController@index');
    $router->addRoute('POST', "/{$ap}/languages/install",                        'LanguageAdminController@install');
    $router->addRoute('POST', "/{$ap}/languages/toggle",                         'LanguageAdminController@toggle');
    $router->addRoute('POST', "/{$ap}/languages/default",                        'LanguageAdminController@setDefault');
    $router->addRoute('POST', "/{$ap}/languages/delete",                         'LanguageAdminController@delete');
    $router->addRoute('GET',  "/{$ap}/languages/export/{code:[a-z0-9_-]+}",     'LanguageAdminController@export');

    // Language Switcher Public Route
    $router->addRoute('GET',  '/lang/{code:[a-z0-9_-]+}',                        'AuthController@switchLanguage');

    // User & Access Management
    $router->addRoute('GET',  "/{$ap}/users",                                    'UserAdminController@index');
    $router->addRoute('POST', "/{$ap}/users/create",                             'UserAdminController@create');
    $router->addRoute('GET',  "/{$ap}/users/{id:\d+}",                           'UserAdminController@view');
    $router->addRoute('POST', "/{$ap}/users/{id:\d+}/role",                      'UserAdminController@updateRole');
    $router->addRoute('POST', "/{$ap}/users/{id:\d+}/status",                    'UserAdminController@updateStatus');
    $router->addRoute('POST', "/{$ap}/users/{id:\d+}/password",                  'UserAdminController@resetPassword');
    $router->addRoute('POST', "/{$ap}/users/{id:\d+}/delete",                    'UserAdminController@delete');

    // Global Active Sessions & Security
    $router->addRoute('GET',  "/{$ap}/sessions",                                 'SystemAdminController@sessions');
    $router->addRoute('POST', "/{$ap}/sessions/{id:\d+}/revoke",                 'SystemAdminController@revokeSession');
    $router->addRoute('POST', "/{$ap}/sessions/revoke-user/{user_id:\d+}",       'SystemAdminController@revokeUserSessions');

    // Personal Access Tokens & API Keys
    $router->addRoute('GET',  "/{$ap}/tokens",                                   'SystemAdminController@tokens');
    $router->addRoute('POST', "/{$ap}/tokens/{id:\d+}/revoke",                   'SystemAdminController@revokeToken');

    // System Health, Diagnostics & Maintenance
    $router->addRoute('GET',  "/{$ap}/system",                                   'SystemAdminController@system');
    $router->addRoute('POST', "/{$ap}/system/clear-cache",                       'SystemAdminController@clearCache');
    $router->addRoute('POST', "/{$ap}/system/smart-clean",                       'SystemAdminController@smartClean');
    $router->addRoute('POST', '/api/v1/maintenance/clear-cache',                 'SystemAdminController@purgeCache');

    $router->addRoute('POST', "/{$ap}/system/optimize-db",                       'SystemAdminController@optimizeDb');
    $router->addRoute('POST', "/{$ap}/system/optimize-git",                      'SystemAdminController@optimizeGit');

    // Global Webhooks & Integrations
    $router->addRoute('GET',  "/{$ap}/webhooks",                                 'WebhookAdminController@index');
    $router->addRoute('POST', "/{$ap}/webhooks/{id:\d+}/toggle",                 'WebhookAdminController@toggle');
    $router->addRoute('POST', "/{$ap}/webhooks/{id:\d+}/delete",                 'WebhookAdminController@delete');

    // Device Database & Catalog Management
    $router->addRoute('GET',  "/{$ap}/devices",                                  'DeviceManageController@index');
    $router->addRoute('POST', "/{$ap}/devices/update",                           'DeviceManageController@update');
    $router->addRoute('GET',  "/{$ap}/devices/check",                            'DeviceManageController@check');
    $router->addRoute('POST', "/{$ap}/devices/lookup",                           'DeviceManageController@lookup');

    // Public short download link gateway
    $router->addRoute('GET',  '/d/{code:[a-zA-Z0-9_-]+}',                        'DownloadCenterController@publicDownloadPage');
    $router->addRoute('POST', '/d/{code:[a-zA-Z0-9_-]+}/unlock',                 'DownloadCenterController@publicUnlock');
    $router->addRoute('POST', '/d/{code:[a-zA-Z0-9_-]+}/report',                 'DownloadCenterController@publicReportBrokenLink');
    $router->addRoute('GET',  '/d/{code:[a-zA-Z0-9_-]+}/get',                    'DownloadCenterController@publicServeFile');
    $router->addRoute('GET',  '/d/{code:[a-zA-Z0-9_-]+}/get/{token:[a-zA-Z0-9_.-]+}',    'DownloadCenterController@publicServeFile');
    // Public/Admin aliases for downloads guide
    $router->addRoute('GET',  '/downloads/guide',                                'DownloadCenterController@adminGuide');
    $router->addRoute('GET',  "/{$ap}/downloads/guide",                          'DownloadCenterController@adminGuide');
    $router->addRoute('POST', "/{$ap}/downloads/guide/regenerate-token",         'DownloadCenterController@adminRegenerateUpdateKey');

    // Download API bridge
    $router->addRoute('POST', '/api/downloads/generate',                         'DownloadApiController@generate');

    $router->addRoute('GET',  "/{$ap}/ssh-keys",                                 'SshKeyController@index');
    $router->addRoute('POST', "/{$ap}/ssh-keys",                                 'SshKeyController@store');
    $router->addRoute('POST', "/{$ap}/ssh-keys/{id}/delete",                     'SshKeyController@delete');

    // Git Smart HTTP protocol (These MUST come before generic {user}/{repo} routes)
    $router->addRoute('GET',  '/{user}/{repo:.+\\.git}/info/refs',          'GitHttpController@infoRefs');
    $router->addRoute('POST', '/{user}/{repo:.+\\.git}/git-upload-pack',    'GitHttpController@uploadPack');
    $router->addRoute('POST', '/{user}/{repo:.+\\.git}/git-receive-pack',   'GitHttpController@receivePack');

    // RSS Commits Feed
    $router->addRoute('GET',  '/{user}/{repo}.rss',                      'RepoController@rss');
    $router->addRoute('GET',  '/{user}/{repo}/rss',                      'RepoController@rss');

    // Social interactions (Star and Watch)
    $router->addRoute('POST', '/{user}/{repo}/star',  'SocialController@toggleStar');
    $router->addRoute('POST', '/{user}/{repo}/watch', 'SocialController@toggleWatch');

    // Fork
    $router->addRoute('GET',  '/{user}/{repo}/fork',                 'ForkController@create');
    $router->addRoute('POST', '/{user}/{repo}/fork',                 'ForkController@fork');

    // Releases
    $router->addRoute('GET',  '/{user}/{repo}/releases',                       'ReleaseController@index');
    $router->addRoute('GET',  '/{user}/{repo}/releases/new',                   'ReleaseController@newForm');
    $router->addRoute('POST', '/{user}/{repo}/releases/new',                   'ReleaseController@store');
    $router->addRoute('POST', '/{user}/{repo}/releases/notes/generate',         'ReleaseController@generateNotes');
    $router->addRoute('GET',  '/{user}/{repo}/releases/tag/{tag:[^/]+}',       'ReleaseController@show');
    $router->addRoute('GET',  '/{user}/{repo}/releases/{id:\d+}/edit',         'ReleaseController@editForm');
    $router->addRoute('POST', '/{user}/{repo}/releases/{id:\d+}/edit',         'ReleaseController@edit');
    $router->addRoute('POST', '/{user}/{repo}/releases/{id:\d+}/publish',      'ReleaseController@publish');
        $router->addRoute('GET',  '/{user}/{repo}/releases/repo-binaries',       'ReleaseController@scanRepoBinaries');
    $router->addRoute('POST', '/{user}/{repo}/releases/attach-repo-binary',    'ReleaseController@attachRepoBinary');
    $router->addRoute('POST', '/{user}/{repo}/releases/assets/upload',       'ReleaseController@assetDirectUpload');
    $router->addRoute('POST', '/{user}/{repo}/releases/assets/link-unified', 'ReleaseController@assetLinkUnified');
$router->addRoute('POST', '/{user}/{repo}/releases/assets/init',           'ReleaseController@assetUploadInit');
    $router->addRoute('POST', '/{user}/{repo}/releases/assets/chunk',          'ReleaseController@assetUploadChunk');
    $router->addRoute('POST', '/{user}/{repo}/releases/assets/complete',       'ReleaseController@assetUploadComplete');
    $router->addRoute('POST', '/{user}/{repo}/releases/assets/{id:\d+}/delete','ReleaseController@assetDelete');
    $router->addRoute('GET',  '/{user}/{repo}/releases/download/{id:\d+}',     'ReleaseController@assetDownload');
    $router->addRoute('GET',  '/{user}/{repo}/releases/download/{tag:[^/]+}/{filename:.+}', 'ReleaseController@assetDownloadByTag');
    $router->addRoute('POST', '/{user}/{repo}/releases/{id:\d+}/delete',       'ReleaseController@delete');
    $router->addRoute('POST', '/{user}/{repo}/tags/delete',                    'ReleaseController@tagDelete');
    $router->addRoute('GET',  '/{user}/{repo}/releases.rss',                  'ReleaseController@feed');
    $router->addRoute('GET',  '/{user}/{repo}/releases/rss',                 'ReleaseController@feed');

    // Web File Editor & Creation
    $router->addRoute('GET',  '/{user}/{repo}/new[/{ref}]',          'RepoController@createFile');
    $router->addRoute('GET',  '/{user}/{repo}/edit/{ref}/{path:.*}', 'RepoController@editFile');
    $router->addRoute('POST', '/{user}/{repo}/file/save',            'RepoController@saveFile');
$router->addRoute('POST', '/{user}/{repo}/file/delete',          'RepoController@deleteFile');

    // Branch management, Compare & Merge
    $router->addRoute('GET',  '/{user}/{repo}/branches',             'BranchController@index');
    $router->addRoute('GET',  '/{user}/{repo}/branches/new',         'BranchController@createForm');
    $router->addRoute('POST', '/{user}/{repo}/branches/new',         'BranchController@store');
    $router->addRoute('POST', '/{user}/{repo}/branches/delete',      'BranchController@delete');
    $router->addRoute('POST', '/{user}/{repo}/branches/default',     'BranchController@setDefault');
    $router->addRoute('GET',  '/{user}/{repo}/compare',              'BranchController@compare');
    $router->addRoute('GET',  '/{user}/{repo}/compare/{spec:.*}',    'BranchController@compare');
    $router->addRoute('POST', '/{user}/{repo}/merge',                'BranchController@merge');

    // Repo browsing & history
    $router->addRoute('GET',  '/{user}/{repo}',                              'RepoController@show');
    $router->addRoute('GET',  '/{user}/{repo}/languages',                    'RepoController@languages');
    $router->addRoute('GET',  '/{user}/{repo}/tree/{ref}[/{path:.*}]',       'RepoController@tree');
    $router->addRoute('GET',  '/{user}/{repo}/blob/{ref}/{path:.*}',         'RepoController@blob');
    $router->addRoute('GET',  '/{user}/{repo}/raw/{ref}/{path:.*}',          'RepoController@raw');
    $router->addRoute('GET',  '/{user}/{repo}/blame/{ref}/{path:.*}',        'RepoController@blame');
    $router->addRoute('GET',  '/{user}/{repo}/commits[/{ref:.*}]',          'RepoController@commits');
    $router->addRoute('GET',  '/{user}/{repo}/activity',                    'RepoController@activity');
    $router->addRoute('GET',  '/{user}/{repo}/insights',                    'RepoController@insights');
    $router->addRoute('GET',  '/{user}/{repo}/commit/{hash}',                'RepoController@commit');
    $router->addRoute('GET',  '/{user}/{repo}/tags',                         'RepoController@tagsIndex');

    // Issues
    $router->addRoute('GET',  '/{user}/{repo}/issues',                 'IssuesController@index');
    $router->addRoute('GET',  '/{user}/{repo}/issues/new',             'IssuesController@newIssue');
    $router->addRoute('POST', '/{user}/{repo}/issues',                 'IssuesController@create');
    $router->addRoute('POST', '/{user}/{repo}/issues/labels',          'IssuesController@createLabel');
    $router->addRoute('POST', '/{user}/{repo}/issues/labels/{id:\d+}/delete', 'IssuesController@deleteLabel');
    $router->addRoute('POST', '/{user}/{repo}/issues/milestones',      'IssuesController@createMilestone');
    $router->addRoute('POST', '/{user}/{repo}/issues/milestones/{id:\d+}/close',   'IssuesController@toggleMilestone');
    $router->addRoute('POST', '/{user}/{repo}/issues/milestones/{id:\d+}/delete',   'IssuesController@deleteMilestone');
    $router->addRoute('POST', '/{user}/{repo}/issues/{id:\d+}/metadata', 'IssuesController@updateMetadata');
    $router->addRoute('GET',  '/{user}/{repo}/issues/{id:\d+}',        'IssuesController@show');
    $router->addRoute('POST', '/{user}/{repo}/issues/{id:\d+}/comment','IssuesController@comment');
    $router->addRoute('POST', '/{user}/{repo}/issues/{id:\d+}/status', 'IssuesController@updateStatus');

    // Pull requests
    $router->addRoute('GET',  '/{user}/{repo}/pulls',                    'PullRequestController@index');
    $router->addRoute('GET',  '/{user}/{repo}/pulls/new',                'PullRequestController@create');
    $router->addRoute('POST', '/{user}/{repo}/pulls/new',                'PullRequestController@store');
    $router->addRoute('GET',  '/{user}/{repo}/pull/{number:\d+}',        'PullRequestController@show');
    $router->addRoute('GET',  '/{user}/{repo}/pull/{number:\d+}/files',  'PullRequestController@showFiles');
    $router->addRoute('POST', '/{user}/{repo}/pull/{number:\d+}/comment','PullRequestController@comment');
    $router->addRoute('POST', '/{user}/{repo}/pull/{number:\d+}/review-comment', 'PullRequestController@reviewComment');
    $router->addRoute('POST', '/{user}/{repo}/pull/{number:\d+}/merge',  'PullRequestController@merge');
    $router->addRoute('POST', '/{user}/{repo}/pull/{number:\d+}/approval','PullRequestController@approval');
    $router->addRoute('POST', '/{user}/{repo}/pull/{number:\d+}/close',  'PullRequestController@toggle');

    // Repository settings (owner or creator)
    $router->addRoute('GET',  '/{user}/{repo}/settings',                          'RepoSettingsController@index');
    $router->addRoute('POST', '/{user}/{repo}/settings',                          'RepoSettingsController@update');
    $router->addRoute('GET',  '/{user}/{repo}/settings/collaborators',           'RepoSettingsController@collaborators');
    $router->addRoute('POST', '/{user}/{repo}/settings/collaborators',            'RepoSettingsController@collaboratorsAdd');
    $router->addRoute('POST', '/{user}/{repo}/settings/collaborators/{id:\d+}/delete', 'RepoSettingsController@collaboratorsRemove');
    $router->addRoute('GET',  '/{user}/{repo}/settings/branches',                'RepoSettingsController@branches');
    $router->addRoute('POST', '/{user}/{repo}/settings/branches',                 'RepoSettingsController@branchesAdd');
    $router->addRoute('POST', '/{user}/{repo}/settings/branches/{id:\d+}/delete', 'RepoSettingsController@branchesRemove');
    $router->addRoute('GET',  '/{user}/{repo}/settings/webhooks',                'RepoSettingsController@webhooks');
    $router->addRoute('POST', '/{user}/{repo}/settings/webhooks',                 'RepoSettingsController@webhooksAdd');
    $router->addRoute('POST', '/{user}/{repo}/settings/webhooks/{id:\d+}/delete', 'RepoSettingsController@webhooksRemove');
    $router->addRoute('POST', '/{user}/{repo}/settings/delete',                   'RepoSettingsController@delete');
    $router->addRoute('POST', '/{user}/{repo}/delete',                            'RepoSettingsController@delete');
    $router->addRoute('POST', '/{user}/{repo}/settings/sync',                     'RepoSettingsController@sync');

    // Wiki
    $router->addRoute('GET',  '/{user}/{repo}/wiki',                   'WikiController@index');
    $router->addRoute('GET',  '/{user}/{repo}/wiki/new',               'WikiController@newPage');
    $router->addRoute('POST', '/{user}/{repo}/wiki',                   'WikiController@store');
    $router->addRoute('POST', '/{user}/{repo}/wiki/preview',           'WikiController@preview');
    $router->addRoute('GET',  '/{user}/{repo}/wiki/{page}/history',    'WikiController@history');
    $router->addRoute('GET',  '/{user}/{repo}/wiki/{page}/revision/{id:\d+}', 'WikiController@revision');
    $router->addRoute('POST', '/{user}/{repo}/wiki/{page}/restore/{id:\d+}', 'WikiController@restore');
    $router->addRoute('GET',  '/{user}/{repo}/wiki/{page}',            'WikiController@show');
    $router->addRoute('GET',  '/{user}/{repo}/wiki/{page}/edit',       'WikiController@edit');
    $router->addRoute('POST', '/{user}/{repo}/wiki/{page}/update',     'WikiController@update');
    $router->addRoute('POST', '/{user}/{repo}/wiki/{page}/delete',     'WikiController@delete');
    $router->addRoute('GET',  '/{user}/{repo}/archive/{ref}.zip',      'RepoController@archive');
    $router->addRoute('GET',  '/{user}/{repo}/archive/{ref}.tar.gz',  'RepoController@archiveTarGz');
    $router->addRoute('GET',  '/{user}/{repo}/search',                'SearchController@repoSearch');
    // Canonical GitHub-style profile — MUST stay last so every static
    // route registers before this dynamic catch-all (FastRoute shadowing).
    $router->addRoute('GET',  '/{username:[a-zA-Z0-9._-]+}', 'AccountController@profile');
};
