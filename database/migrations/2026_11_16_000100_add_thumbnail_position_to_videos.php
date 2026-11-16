<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // 自動サムネイルを動画のどの位置（%）から作るか。NULL なら既定（10%）
            $table->unsignedTinyInteger('thumbnail_position')->nullable()->after('thumbnail_url');
        });
    }

    public function down(): void
    {
        Schema::table('videos', fn (Blueprint $t) => $t->dropColumn('thumbnail_position'));
    }
};
