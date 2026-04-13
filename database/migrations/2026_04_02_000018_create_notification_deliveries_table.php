<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();
        $constraint = 'chk_'.$prefix.'notification_deliveries_state';

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->comment('UUID commun aux livraisons declenchees par le meme evenement');
            $table->uuid('attempt_id')->comment('UUID unique de cette tentative de livraison');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->uuid('notification_id')->nullable()->comment('ID de notifications.id si le canal database est utilise');
            $table->string('notification_type')->comment('Classe de notification Laravel (FQCN)');
            $table->string('notifiable_type')->comment('Morph target, ex: App\\Models\\User');
            $table->unsignedBigInteger('notifiable_id')->comment('Cle du modele notifiable');
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('channel', 50)->comment('mail | database | broadcast | slack | sms | custom');
            $table->string('recipient_email')->nullable();
            $table->string('status', 50);
            $table->string('provider', 50)->nullable()->comment('smtp | ses | postmark | database | broadcast | custom');
            $table->string('provider_message_id')->nullable()->comment('ID retourne par le provider ou trace technique');
            $table->json('payload')->nullable()->comment("Snapshot du contexte d'envoi : sujet, pieces jointes, metadata");
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique('attempt_id', 'uq_notification_deliveries_attempt_id');
            $table->unique(
                ['event_id', 'channel', 'notifiable_type', 'notifiable_id', 'attempt_number'],
                'uq_notification_deliveries_event_channel_notifiable_attempt'
            );
            $table->index('event_id', 'idx_notification_deliveries_event_id');
            $table->index('notification_id', 'idx_notification_deliveries_notification_id');
            $table->index('notification_type', 'idx_notification_deliveries_notification_type');
            $table->index(['notifiable_type', 'notifiable_id'], 'idx_notification_deliveries_notifiable');
            $table->index('reservation_id', 'idx_notification_deliveries_reservation_id');
            $table->index('document_id', 'idx_notification_deliveries_document_id');
            $table->index('channel', 'idx_notification_deliveries_channel');
            $table->index('status', 'idx_notification_deliveries_status');
            $table->index('recipient_email', 'idx_notification_deliveries_recipient_email');
            $table->index(['channel', 'status'], 'idx_notification_deliveries_channel_status');
            $table->index('sent_at', 'idx_notification_deliveries_sent_at');
            $table->index('failed_at', 'idx_notification_deliveries_failed_at');
            $table->index('created_at', 'idx_notification_deliveries_created_at');
        });

        DB::statement("
            ALTER TABLE {$prefix}notification_deliveries
            ADD CONSTRAINT {$constraint}
            CHECK (
                attempt_number >= 1
                AND (sent_at IS NULL OR failed_at IS NULL)
                AND (
                    (status = 'pending' AND sent_at IS NULL AND failed_at IS NULL)
                    OR (status = 'sent' AND sent_at IS NOT NULL)
                    OR (status = 'failed' AND failed_at IS NOT NULL)
                )
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
