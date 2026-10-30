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
        Schema::create('despatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            
            $table->string('serie', 4); // Ej: T001
            $table->integer('correlative');
            
            $table->date('issue_date');
            $table->date('transfer_date');
            $table->string('transfer_reason_code', 2)->default('01'); // 01 = Venta
            $table->string('transfer_reason_description')->nullable();
            $table->string('mode_of_transport', 2)->default('02'); // 02 = Privado, 01 = Público
            $table->string('unit_of_measure', 3)->default('KGM'); // KGM = Kilogramos
            $table->decimal('total_weight', 10, 2)->default(1.00);
            $table->integer('number_of_packages')->default(1);
            $table->text('observations')->nullable();
            
            // Origen y Destino
            $table->string('departure_ubigeo', 6);
            $table->string('departure_address');
            $table->string('arrival_ubigeo', 6);
            $table->string('arrival_address');
            
            // Privado
            $table->string('driver_document_type', 1)->nullable(); // 1 = DNI, etc.
            $table->string('driver_document_number')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_license_number')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->string('secondary_vehicle_plate')->nullable();
            
            // Público
            $table->string('carrier_document_type', 1)->nullable();
            $table->string('carrier_document_number')->nullable();
            $table->string('carrier_name')->nullable();
            
            // SUNAT
            $table->string('sunat_status')->default('pendiente'); // pendiente, aceptado, anulado
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            
            $table->timestamps();
        });

        Schema::create('despatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despatch_id')->constrained('despatches')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 10, 2);
            $table->string('unit_code', 3)->default('NIU');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('despatch_items');
        Schema::dropIfExists('despatches');
    }
};
