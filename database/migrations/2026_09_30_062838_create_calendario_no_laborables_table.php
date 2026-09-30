<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendario_no_laborables', function (Blueprint $table) {
            $table->id();
            $table->string('titulo'); // Nombre o descripción del día no laborable
            $table->date('fecha'); // La fecha específica
            $table->text('descripcion')->nullable(); // Detalles adicionales opcionales
            $table->boolean('es_recurrente')->default(false); // Si se repite cada año
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendario_no_laborables');
    }
};