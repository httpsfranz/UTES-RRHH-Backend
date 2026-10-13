# database/sql

Scripts T-SQL que construyen el esquema de `DB_ControlAsistencia`. Son la **fuente de verdad** de la base de datos: lo que está acá es lo que existe.

Se aplican con `php artisan migrate`. No se ejecutan a mano en SSMS.

## Convención de nombres

```
V001__esquema_base.sql                  ← cambio hacia adelante
R001__rollback_esquema_base.sql         ← su reversa, para migrate:rollback
```

`V` de *version*, número de tres dígitos correlativo, doble guión bajo, descripción en `snake_case`. El número define el orden de aplicación.

## Historial de scripts

| Script | Qué hace |
|---|---|
| `V001__esquema_base.sql` | Estructura completa: 13 esquemas, 78 tablas, restricciones, índices y vistas (sin datos). |
| `V002__tramo_tolerancia_segun_rit.sql` | `Configuracion.TramoTolerancia`: agrega `TramoToleranciaMinutosDescuento` y `TramoToleranciaEsInasistencia` para representar la escala real del Art. 22 del RIT (descuento fijo por tramo; desde el minuto 31, inasistencia). |
| `V003__vinculo_vigente_segundo_vinculo_medico.sql` | Elimina `UX_VinculoLaboral_Vigente`: el Art. 86 del RIT exceptúa al personal médico de la regla de un solo vínculo. La regla general la sigue aplicando `VinculoLaboralRequest`. |
| `V004__asistencia_diaria_origen.sql` | `Asistencia.AsistenciaDiaria`: agrega `AsistenciaDiariaOrigen` (`MANUAL` por omisión o `CALCULADO`) para que el motor de cálculo no pise lo digitado a mano. |

## Las dos reglas

**1. Un script aplicado no se edita nunca.** El trait `App\Support\RunsSqlFile` guarda un SHA-256 de cada archivo en `dbo.SqlScriptAplicado`. Si editas uno ya aplicado, la próxima migración aborta. Está hecho a propósito: tú verías tu cambio, el resto del equipo nunca, y nadie se enteraría hasta que algo falle en producción.

Para corregir algo de `V002`, se crea `V003`.

**2. Cada `V` necesita su migración-cáscara** en `database/migrations/`, o Laravel no sabe que existe. Copia la de `V001` y cambia el nombre del archivo.

## Cómo agregar un cambio

```sql
-- V002__tolerancia_por_establecimiento.sql
ALTER TABLE Configuracion.Tolerancia
    ADD ToleranciaEessId INT NULL;
GO
```

```php
// 2026_09_20_000002_v002_tolerancia_por_establecimiento.php
public function up(): void
{
    $this->runSqlFile('V002__tolerancia_por_establecimiento.sql');
}
```

Probar con `migrate` en local, commit, PR. Nunca push directo a `develop`.

## Sobre los `GO`

`GO` no es T-SQL: es un separador de lotes que solo entienden SSMS y sqlcmd. El trait parte el archivo por esas líneas y ejecuta lote por lote.

Consecuencia práctica: `CREATE VIEW`, `CREATE SCHEMA` y `CREATE PROCEDURE` deben ser la primera sentencia de su lote, así que llevan un `GO` inmediatamente antes. Si lo olvidas, SQL Server responde *"CREATE VIEW must be the first statement in a query batch"*.

## Qué NO va en estos scripts

- **Datos de ningún tipo.** Ni trabajadores ficticios ni catálogos. Los scripts son solo estructura (DDL): esquemas, tablas, restricciones, índices, vistas. Todos los `INSERT` viven en `database/seeders/`:
  - `CatalogosSeeder` — roles, regímenes laborales, tipos de papeleta, estados de asistencia, turnos base, etc. Sin ellos el sistema no arranca. **Idempotente** (inserta solo lo que falta): es el único seeder seguro en producción.
  - `DatosPruebaSeeder` — datos de prueba en dos capas: los **casos puntuales** de cada módulo (con sus estados y reglas del RIT) y la **expansión de volumen** (`Concerns/Volumen*.php`), que puebla toda la Red como un sistema ya en uso: ~190 trabajadores repartidos en 16 establecimientos, programación de turnos de julio a noviembre, ~23 000 marcaciones, solicitudes, asistencia calculada con el motor del sistema, consolidados y liquidaciones de julio a setiembre, y la operación alrededor (responsables, PAD, supervisiones, portería, sesiones, auditoría). Trabajadores de volumen: DNI `46NNNNNN` y vínculo `VL-1NNN`.
  - `DatabaseSeeder` — **seeder maestro**: vacía las 78 tablas (conserva la estructura, reinicia los Id) y vuelve a sembrar catálogos + datos de prueba. Se niega a correr en producción.
- **Consultas de diagnóstico.** Van en `database/diagnostico/`, para correr en SSMS. Un `SELECT` dentro de una migración deja un cursor abierto y rompe la consulta siguiente.

## Cómo dejar la base en su estado inicial

```bash
php artisan db:seed                        # solo datos: vacía y vuelve a sembrar (rápido, conserva estructura)
php artisan migrate:fresh --seed           # estructura + datos desde cero (tras cambiar un V0XX)
php artisan db:seed --class=CatalogosSeeder  # solo catálogos, sin borrar nada (producción)
SEED_VOLUMEN=false php artisan db:seed     # solo los casos puntuales, sin la expansión de volumen (~1 s en vez de ~45 s)
```

`php artisan db:seed` deja la base **con volumen** (unos 45 segundos). Es el estado para recorrer el sistema.

### Base de datos de las pruebas

PHPUnit **no usa la base de trabajo**: corre en `DB_ControlAsistencia_Test`, que solo lleva los casos puntuales (sus pruebas verifican esos casos, no el volumen). Se crea y se siembra una vez con:

```bash
php artisan pruebas:preparar               # crea DB_ControlAsistencia_Test, aplica los scripts V*.sql y siembra sin volumen
vendor/bin/phpunit                         # corre en esa base; la de trabajo no se toca
PROBAR_VOLUMEN=1 vendor/bin/phpunit tests/Feature/VolumenSeederTest.php   # verifica la expansión de volumen (~1 min)
```

Las pruebas usan `DatabaseTransactions`, así que no hace falta resembrar entre corridas. Si cambia un `V0XX`, vuelve a correr `pruebas:preparar`. La prueba del volumen conviene correrla cuando cambie el motor de cálculo, la consolidación, la liquidación o el propio seeder.

> **Excepción única a la regla 1:** el 2026-09-30 se retiró de `V001` la sección 20 (los `INSERT`) para separar estructura de datos. Por eso el checksum cambió: cada integrante corre `php artisan migrate:fresh --seed` una vez.
