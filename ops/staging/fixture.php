<?php

use App\Models\ReportPdfExport;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (getenv('STAGE8_CONTAINER') === '1') {
    require '/app/backend/vendor/autoload.php';
    $app = require '/app/backend/bootstrap/app.php';
} else {
    $app = require __DIR__.'/bootstrap.php';
}
$app->make(Kernel::class)->bootstrap();

$root = getenv('STAGE8_CONTAINER') === '1' ? '/runtime' : realpath(getenv('STAGE8_ROOT'));
$target = DB::connection()->getDriverName() === 'sqlite' && realpath(config('database.connections.sqlite.database')) === $root.DIRECTORY_SEPARATOR.'database.sqlite';
if (getenv('STAGE8_CONTAINER') === '1') {
    $target = DB::connection()->getDriverName() === 'mysql' && config('database.connections.mysql.database') === 'pentagon_stage8_local' && config('database.connections.mysql.host') === 'mysql';
}
if (config('app.env') !== 'staging' || ! $target) {
    throw new RuntimeException('Isolated synthetic target required.');
}
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    if (User::count()) {
        throw new RuntimeException('Refusing to reseed existing accounts.');
    }
    if (getenv('STAGE8_CONTAINER') === '1') {
        if (! getenv('STAGE8_FIXTURE_PASSWORD')) {
            throw new RuntimeException('Fixture password required.');
        }
        file_put_contents($root.'/credentials.json', json_encode(['password' => getenv('STAGE8_FIXTURE_PASSWORD')]));
        file_put_contents($root.'/.synthetic-stage8', 'synthetic-only');
        mkdir($root.'/evidence', 0700, true);
    }
    $password = json_decode(file_get_contents($root.'/credentials.json'), true)['password'];
    $ids = [];
    foreach (['root' => 'super_admin', 'a' => 'admin', 'b' => 'admin', 'c' => 'admin', 'manager' => 'seo_manager', 'executive' => 'seo_executive', 'viewer' => 'client_viewer', 'inactive' => 'admin'] as $name => $role) {
        $u = new User;
        $u->forceFill(['name' => 'Synthetic '.ucfirst($name), 'email' => $name.'@stage8.test', 'password' => Hash::make($password), 'role' => $role, 'is_super_admin' => $name === 'root', 'is_active' => $name !== 'inactive', 'email_verified_at' => now()])->save();
        $ids[$name] = $u->id;
    }
    file_put_contents($root.'/accounts.json', json_encode($ids));
    echo "Synthetic accounts created.\n";
} elseif ($mode === 'load-users') {
    $state = json_decode(file_get_contents($root.'/workflow-state.json'), true);
    $project = $state['tenants'][0]['project'];
    $tokens = [];
    $password = json_decode(file_get_contents($root.'/credentials.json'), true)['password'];
    for ($i = 0; $i < 50; $i++) {
        $u = User::firstOrNew(['email' => 'load'.$i.'@stage8.test']);
        $u->forceFill(['name' => 'Synthetic load '.$i, 'email' => 'load'.$i.'@stage8.test', 'password' => Hash::make($password), 'role' => 'seo_executive', 'is_super_admin' => false, 'is_active' => true])->save();
        DB::table('project_memberships')->insertOrIgnore(['project_id' => $project, 'user_id' => $u->id, 'assignment_role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $tokens[] = $u->email;
    }
    file_put_contents($root.'/load-accounts.json', json_encode($tokens));
    echo "Created 50 isolated synthetic session users; credentials not printed.\n";
} elseif ($mode === 'benchmark-html') {
    $items = [];
    foreach (ReportPdfExport::all() as $e) {
        $html = $root.'/evidence/render-'.$e->id.'.html';
        file_put_contents($html, view('reports.pdf', ['d' => $e->payload, 'sections' => $e->sections, 'profile' => $e->profile])->render());
        $items[] = ['id' => $e->id, 'html' => $html, 'output' => $root.'/evidence/measured-'.$e->id.'.pdf'];
    }file_put_contents($root.'/evidence/render-inputs.json', json_encode($items));
} elseif ($mode === 'inspect') {
    $exports = [];
    foreach (ReportPdfExport::all() as $e) {
        $exports[] = ['id' => $e->id, 'profile' => $e->profile, 'sections' => $e->sections, 'type' => $e->type, 'status' => $e->status, 'path' => $e->storage_path ? Storage::disk('local')->path($e->storage_path) : null, 'bytes' => $e->file_size, 'checksum' => $e->checksum, 'payload' => $e->payload, 'created_at' => $e->created_at, 'updated_at' => $e->updated_at];
    }file_put_contents($root.'/evidence/export-artifacts.json', json_encode($exports));
    echo json_encode(['users' => User::count(), 'clients' => DB::table('clients')->count(), 'documents' => DB::table('report_documents')->count(), 'records' => DB::table('normalized_records')->count(), 'jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'publications' => DB::table('report_publications')->count(), 'exports' => array_map(fn ($e) => array_diff_key($e, ['payload' => 1]), $exports)], JSON_PRETTY_PRINT);
} else {
    throw new RuntimeException('Unknown fixture operation.');
}
