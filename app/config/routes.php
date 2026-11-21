<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */
//ha
/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Dashboard::index');

require_once APP_DIR . 'middlewares/Cors_middleware.php';
require_once APP_DIR . 'middlewares/Auth_middleware.php';
require_once APP_DIR . 'middlewares/Role_middleware.php';

get_config([
    'middlewares' => [
        'api_cors' => new Cors_middleware(),
        'auth' => new Auth_middleware(),
        'cao_admin' => new Role_middleware(['cao_admin']),
        'cao_staff' => new Role_middleware(['cao_staff']),
        'barangay_personnel' => new Role_middleware(['barangay_personnel']),
        'agriculture_staff' => new Role_middleware(['cao_admin', 'cao_staff', 'barangay_personnel']),
        'dashboard_access' => new Role_middleware(['cao_admin', 'cao_staff', 'barangay_personnel']),
        'rice_access' => new Role_middleware(['cao_admin', 'cao_staff', 'barangay_personnel', 'farmer']),
        'rice_management' => new Role_middleware(['cao_admin', 'cao_staff', 'barangay_personnel']),
        'rice_review' => new Role_middleware(['cao_admin', 'cao_staff']),
        'farmer' => new Role_middleware(['farmer']),
    ],
]);

$router->post('/api/auth/login', 'Auth::login')->middleware('api_cors');
$router->options('/api/auth/login', 'Auth::options')->middleware('api_cors');
$router->post('/api/auth/google', 'Auth::google')->middleware('api_cors');
$router->options('/api/auth/google', 'Auth::options')->middleware('api_cors');
$router->post('/api/auth/password-reset/request', 'Auth::request_password_reset')->middleware('api_cors');
$router->post('/api/auth/password-reset/complete', 'Auth::complete_password_reset')->middleware('api_cors');
$router->options('/api/auth/password-reset/request', 'Auth::options')->middleware('api_cors');
$router->options('/api/auth/password-reset/complete', 'Auth::options')->middleware('api_cors');
$router->get('/api/auth/me', 'Auth::me')->middleware(['api_cors', 'auth']);
$router->patch('/api/auth/profile', 'Auth::update_profile')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/auth/email-preferences', 'Auth::email_preferences')->middleware(['api_cors', 'auth', 'rice_access']);
$router->patch('/api/auth/email-preferences', 'Auth::update_email_preferences')->middleware(['api_cors', 'auth', 'rice_access']);
$router->options('/api/auth/email-preferences', 'Auth::options')->middleware('api_cors');
$router->options('/api/auth/me', 'Auth::options')->middleware('api_cors');
$router->options('/api/auth/profile', 'Auth::options')->middleware('api_cors');
$router->post('/api/auth/logout', 'Auth::logout')->middleware(['api_cors', 'auth']);
$router->options('/api/auth/logout', 'Auth::options')->middleware('api_cors');
$router->get('/api/dashboard', 'Dashboard::data')->middleware(['api_cors', 'auth', 'dashboard_access']);
$router->options('/api/dashboard', 'Auth::options')->middleware('api_cors');
$router->get('/api/farmer/dashboard', 'Farmer::dashboard')->middleware(['api_cors', 'auth', 'farmer']);
$router->options('/api/farmer/dashboard', 'Auth::options')->middleware('api_cors');
$router->get('/api/barangays', 'Agriculture::barangays')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->options('/api/barangays', 'Auth::options')->middleware('api_cors');
$router->get('/api/farmers', 'Agriculture::farmers')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->post('/api/farmers', 'Agriculture::create_farmer')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/farmers/{id}', 'Agriculture::farmer')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->patch('/api/farmers/{id}', 'Agriculture::update_farmer')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->delete('/api/farmers/{id}', 'Agriculture::archive_farmer')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->options('/api/farmers', 'Auth::options')->middleware('api_cors');
$router->options('/api/farmers/{id}', 'Auth::options')->middleware('api_cors');
$router->get('/api/farms', 'Farm_management::index')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/farms/options', 'Farm_management::options')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->post('/api/farms', 'Farm_management::create')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->patch('/api/farms/{id}/location', 'Farm_management::update_location')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->delete('/api/farms/{id}', 'Farm_management::archive')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->options('/api/farms', 'Auth::options')->middleware('api_cors');
$router->options('/api/farms/options', 'Auth::options')->middleware('api_cors');
$router->options('/api/farms/{id}/location', 'Auth::options')->middleware('api_cors');
$router->options('/api/farms/{id}', 'Auth::options')->middleware('api_cors');
$router->get('/api/agriculture/references', 'Crop_livestock::references')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/crop-plantings', 'Crop_livestock::crops')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/crop-plantings', 'Crop_livestock::create_crop')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->delete('/api/crop-plantings/{id}', 'Crop_livestock::archive_crop')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/livestock', 'Crop_livestock::livestock')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/livestock', 'Crop_livestock::create_livestock')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->delete('/api/livestock/{id}', 'Crop_livestock::archive_livestock')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/livestock-health', 'Crop_livestock::health')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/livestock-health', 'Crop_livestock::create_health')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->options('/api/agriculture/references', 'Auth::options')->middleware('api_cors');
$router->options('/api/crop-plantings', 'Auth::options')->middleware('api_cors');
$router->options('/api/crop-plantings/{id}', 'Auth::options')->middleware('api_cors');
$router->options('/api/livestock', 'Auth::options')->middleware('api_cors');
$router->options('/api/livestock/{id}', 'Auth::options')->middleware('api_cors');
$router->options('/api/livestock-health', 'Auth::options')->middleware('api_cors');
$router->get('/api/production/references', 'Production_harvest::references')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/production/options', 'Production_harvest::options')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/production', 'Production_harvest::production')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/production', 'Production_harvest::create_production')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/harvests', 'Production_harvest::harvests')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/harvests', 'Production_harvest::create_harvest')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/reports/harvests', 'Production_harvest::report')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/analytics/harvest-trend', 'Production_harvest::harvest_analytics')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/exports/{module}', 'Data_transfer::export')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/imports', 'Data_transfer::history')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->post('/api/imports/farmers', 'Data_transfer::import_farmers')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->post('/api/imports/{module}', 'Data_transfer::import_records')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->options('/api/imports/{module}', 'Auth::options')->middleware('api_cors');
$router->options('/api/exports/{module}', 'Auth::options')->middleware('api_cors');
$router->options('/api/imports', 'Auth::options')->middleware('api_cors');
$router->options('/api/imports/farmers', 'Auth::options')->middleware('api_cors');
$router->options('/api/production/references', 'Auth::options')->middleware('api_cors');
$router->options('/api/production/options', 'Auth::options')->middleware('api_cors');
$router->options('/api/production', 'Auth::options')->middleware('api_cors');
$router->options('/api/harvests', 'Auth::options')->middleware('api_cors');
$router->options('/api/reports/harvests', 'Auth::options')->middleware('api_cors');
$router->options('/api/analytics/harvest-trend', 'Auth::options')->middleware('api_cors');
$router->get('/api/notifications', 'Operations::notifications')->middleware(['api_cors', 'auth', 'rice_access']);
$router->patch('/api/notifications/{id}/read', 'Operations::mark_notification_read')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/announcements', 'Operations::announcements')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/announcements', 'Operations::create_announcement')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->get('/api/messages', 'Operations::messages')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/messages', 'Operations::create_message')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/calendar', 'Operations::calendar')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/calendar', 'Operations::create_calendar_event')->middleware(['api_cors', 'auth', 'agriculture_staff']);
$router->post('/api/calendar/feed-token', 'Operations::create_calendar_feed_token')->middleware(['api_cors', 'auth', 'rice_access']);
$router->delete('/api/calendar/feed-token', 'Operations::revoke_calendar_feed_token')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/calendar/feed/{token}.ics', 'Operations::calendar_feed');
$router->options('/api/calendar/feed-token', 'Auth::options')->middleware('api_cors');
$router->get('/api/admin/audit-logs', 'Operations::audit_logs')->middleware(['api_cors', 'auth', 'cao_admin']);
$router->options('/api/notifications', 'Auth::options')->middleware('api_cors');
$router->options('/api/notifications/{id}/read', 'Auth::options')->middleware('api_cors');
$router->options('/api/announcements', 'Auth::options')->middleware('api_cors');
$router->options('/api/messages', 'Auth::options')->middleware('api_cors');
$router->options('/api/calendar', 'Auth::options')->middleware('api_cors');
$router->options('/api/admin/audit-logs', 'Auth::options')->middleware('api_cors');
$router->get('/api/rice-varieties', 'Rice::varieties')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/rice-stages', 'Rice::stages')->middleware(['api_cors', 'auth', 'rice_access']);
$router->get('/api/rice-plantings', 'Rice::plantings')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/rice-plantings', 'Rice::create_planting')->middleware(['api_cors', 'auth', 'rice_management']);
$router->get('/api/rice-monitoring', 'Rice::monitoring')->middleware(['api_cors', 'auth', 'rice_access']);
$router->post('/api/rice-monitoring', 'Rice::submit_monitoring')->middleware(['api_cors', 'auth', 'rice_access']);
$router->patch('/api/rice-monitoring/{id}', 'Rice::review_monitoring')->middleware(['api_cors', 'auth', 'rice_review']);
$router->options('/api/rice-varieties', 'Auth::options')->middleware('api_cors');
$router->options('/api/rice-stages', 'Auth::options')->middleware('api_cors');
$router->options('/api/rice-plantings', 'Auth::options')->middleware('api_cors');
$router->options('/api/rice-monitoring', 'Auth::options')->middleware('api_cors');
$router->options('/api/rice-monitoring/{id}', 'Auth::options')->middleware('api_cors');
$router->get('/api/admin/users', 'User_management::index')->middleware(['api_cors', 'auth', 'cao_admin']);
$router->get('/api/admin/users/options', 'User_management::options')->middleware(['api_cors', 'auth', 'cao_admin']);
$router->post('/api/admin/users', 'User_management::create')->middleware(['api_cors', 'auth', 'cao_admin']);
$router->patch('/api/admin/users/{id}', 'User_management::update')->middleware(['api_cors', 'auth', 'cao_admin']);
$router->options('/api/admin/users', 'Auth::options')->middleware('api_cors');
$router->options('/api/admin/users/options', 'Auth::options')->middleware('api_cors');
$router->options('/api/admin/users/{id}', 'Auth::options')->middleware('api_cors');

$router->get('/__cli/migrate', 'System::migrate');
$router->get('/__cli/seed-admin', 'System::seed_admin');
$router->get('/__cli/send-mail', 'System::send_mail');

$router->get('/login', 'Dashboard::index');
$router->get('/reset-password', 'Dashboard::index');
$router->get('/dashboard', 'Dashboard::index');
$router->get('/farmers', 'Dashboard::index');
$router->get('/farms', 'Dashboard::index');
$router->get('/crops', 'Dashboard::index');
$router->get('/livestock', 'Dashboard::index');
$router->get('/admin/accounts', 'Dashboard::index');
$router->get('/admin/audit-logs', 'Dashboard::index');
$router->get('/rice', 'Dashboard::index');
$router->get('/production', 'Dashboard::index');
$router->get('/settings', 'Dashboard::index');
$router->get('/imports', 'Dashboard::index');
$router->get('/reports', 'Dashboard::index');
$router->get('/analytics', 'Dashboard::index');
$router->get('/calendar', 'Dashboard::index');
$router->get('/communications', 'Dashboard::index');
$router->get('/map', 'Dashboard::index');
$router->get('/presentation-demo', 'Dashboard::index');
$router->get('/admin/audit-logs', 'Dashboard::index');
$router->get('/forbidden', 'Dashboard::index');
$router->get('/farmer/dashboard', 'Dashboard::index');