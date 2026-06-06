<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('org_registration_requests', function (Blueprint $table) {
            $table->dropColumn('proof_document_path');
            $table->unsignedBigInteger('proof_document_id')->nullable()->after('comment');
            $table->foreign('proof_document_id', 'fk_org_reg_req_proof_document')
                ->references('id')->on('documents')->nullOnDelete();
        });

        Schema::table('user_org_requests', function (Blueprint $table) {
            $table->dropColumn('proof_document_path');
            $table->unsignedBigInteger('proof_document_id')->nullable()->after('justification');
            $table->foreign('proof_document_id', 'fk_user_org_req_proof_document')
                ->references('id')->on('documents')->nullOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('document_proof_path');
            $table->unsignedBigInteger('proof_document_id')->nullable()->after('is_banned');
            $table->foreign('proof_document_id', 'fk_organizations_proof_document')
                ->references('id')->on('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('org_registration_requests', function (Blueprint $table) {
            $table->dropForeign('fk_org_reg_req_proof_document');
            $table->dropColumn('proof_document_id');
            $table->string('proof_document_path', 500)->nullable()->after('comment');
        });

        Schema::table('user_org_requests', function (Blueprint $table) {
            $table->dropForeign('fk_user_org_req_proof_document');
            $table->dropColumn('proof_document_id');
            $table->string('proof_document_path', 500)->nullable()->after('justification');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign('fk_organizations_proof_document');
            $table->dropColumn('proof_document_id');
            $table->string('document_proof_path', 500)->nullable()->after('is_banned');
        });
    }
};
