<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organization_zapier_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('organization_zapier_settings', 'api_key_hash')) {
                $table->string('api_key_hash', 64)->nullable()->after('zapier_api_key');
                $table->index('api_key_hash', 'idx_org_zapier_api_key_hash');
            }
        });

        $settings = DB::table('organization_zapier_settings')
            ->select('id', 'zapier_api_key', 'api_key_hash')
            ->get();

        foreach ($settings as $setting) {
            if (!empty($setting->api_key_hash)) {
                continue;
            }

            try {
                $plainKey = Crypt::decryptString($setting->zapier_api_key);
                if (!empty($plainKey)) {
                    DB::table('organization_zapier_settings')
                        ->where('id', $setting->id)
                        ->update(['api_key_hash' => hash('sha256', $plainKey)]);
                }
            } catch (\Throwable $e) {
                // Skip rows that cannot be decrypted; they can be reset later.
                continue;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_zapier_settings', function (Blueprint $table) {
            if (Schema::hasColumn('organization_zapier_settings', 'api_key_hash')) {
                $table->dropIndex('idx_org_zapier_api_key_hash');
                $table->dropColumn('api_key_hash');
            }
        });
    }
};
