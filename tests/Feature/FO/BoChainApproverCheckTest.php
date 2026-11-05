<?php

namespace Tests\Feature\FO;

use App\Modules\FO\Approval\Services\BoChainApproverCheck;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gerbang login app approver untuk user BO: lolos bila punya tahap di Approval
 * Chain BO Approval Inbox (user / role / Dept Head = atasan di Employee Center).
 * Koneksi ipro_bo diganti SQLite in-memory dengan tabel minimal.
 */
class BoChainApproverCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.bo_uji' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'ipro.connection' => 'bo_uji',
        ]);
        $s = Schema::connection('bo_uji');
        $s->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('employee_nip')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('deleted_at')->nullable();
        });
        $s->create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $s->create('model_has_roles', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });
        $s->create('approval_chains', function (Blueprint $t) {
            $t->id();
            $t->string('document_type');
            $t->boolean('is_active')->default(true);
        });
        $s->create('approval_chain_steps', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('approval_chain_id');
            $t->string('approver_type');
            $t->string('approver_value');
        });
        $s->create('bs_employees', function (Blueprint $t) {
            $t->id();
            $t->string('nik');
            $t->unsignedBigInteger('atasan_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('deleted_at')->nullable();
        });
    }

    private function bo(): Connection
    {
        return DB::connection('bo_uji');
    }

    private function user(string $email, array $attrs = []): int
    {
        return $this->bo()->table('users')->insertGetId(['email' => $email] + $attrs);
    }

    private function role(int $userId, string $name): void
    {
        $roleId = $this->bo()->table('roles')->where('name', $name)->value('id')
            ?? $this->bo()->table('roles')->insertGetId(['name' => $name]);
        $this->bo()->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'user', 'model_id' => $userId]);
    }

    private function chain(string $doc, string $type, string $value, bool $aktif = true): void
    {
        $id = $this->bo()->table('approval_chains')->insertGetId(['document_type' => $doc, 'is_active' => $aktif]);
        $this->bo()->table('approval_chain_steps')->insert(['approval_chain_id' => $id, 'approver_type' => $type, 'approver_value' => $value]);
    }

    private function cek(string $email): bool
    {
        return (new BoChainApproverCheck)->isApprover($email);
    }

    public function test_tahap_tipe_user(): void
    {
        $id = $this->user('gm@jerp.test');
        $this->chain('memo_internal', 'user', (string) $id);

        $this->assertTrue($this->cek('gm@jerp.test'));
        $this->assertFalse($this->cek('lain@jerp.test'));
    }

    public function test_tahap_tipe_role(): void
    {
        $this->role($this->user('acc@jerp.test'), 'Accounting');
        $this->role($this->user('sales@jerp.test'), 'SalesManager');
        $this->chain('ap_invoice', 'role', 'Accounting');

        $this->assertTrue($this->cek('acc@jerp.test'));
        $this->assertFalse($this->cek('sales@jerp.test'));
    }

    public function test_chain_nonaktif_atau_bukan_jenis_inbox_tidak_dihitung(): void
    {
        $id = $this->user('gm@jerp.test');
        $this->chain('memo_internal', 'user', (string) $id, aktif: false);
        $this->chain('booking_discount', 'user', (string) $id);

        $this->assertFalse($this->cek('gm@jerp.test'));
    }

    public function test_dept_head_lolos_selama_punya_bawahan_aktif(): void
    {
        $this->chain('ap_berkas', 'assigned', 'DeptHead');
        $atasan = $this->bo()->table('bs_employees')->insertGetId(['nik' => 'EMP-DH']);
        $this->user('dh@jerp.test', ['employee_nip' => 'EMP-DH']);
        $this->user('solo@jerp.test', ['employee_nip' => 'EMP-SOLO']);
        $this->bo()->table('bs_employees')->insert(['nik' => 'EMP-SOLO']);

        $this->assertFalse($this->cek('dh@jerp.test'), 'belum punya bawahan');

        $this->bo()->table('bs_employees')->insert(['nik' => 'EMP-STF', 'atasan_id' => $atasan]);
        $this->assertTrue($this->cek('dh@jerp.test'));
        $this->assertFalse($this->cek('solo@jerp.test'));

        $this->bo()->table('bs_employees')->where('nik', 'EMP-STF')->update(['is_active' => false]);
        $this->assertFalse($this->cek('dh@jerp.test'), 'bawahan nonaktif');
    }

    public function test_superadmin_bo_selalu_lolos(): void
    {
        $this->role($this->user('root@jerp.test'), 'SuperAdmin');

        $this->assertTrue($this->cek('root@jerp.test'));
    }

    public function test_user_bo_nonaktif_atau_dihapus_ditolak(): void
    {
        $a = $this->user('off@jerp.test', ['is_active' => false]);
        $b = $this->user('hapus@jerp.test', ['deleted_at' => now()]);
        $this->chain('memo_internal', 'user', (string) $a);
        $this->chain('memo_internal', 'user', (string) $b);

        $this->assertFalse($this->cek('off@jerp.test'));
        $this->assertFalse($this->cek('hapus@jerp.test'));
    }

    public function test_bo_tidak_terjangkau_dianggap_bukan_approver(): void
    {
        config(['ipro.connection' => 'koneksi_tidak_ada']);

        $this->assertFalse($this->cek('gm@jerp.test'));
    }
}
