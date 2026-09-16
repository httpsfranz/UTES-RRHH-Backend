# database/sql

Scripts T-SQL que construyen el esquema de `DB_ControlAsistencia`. Son la **fuente de verdad** de la base de datos: lo que está acá es lo que existe.

Se aplican con `php artisan migrate`. No se ejecutan a mano en SSMS.

## Convención de nombres

```
V001__esquema_base.sql                  ← cambio hacia adelante
R001__rollback_esquema_base.sql         ← su reversa, para migrate:rollback
```

`V` de *version*, número de tres dígitos correlativo, doble guión bajo, descripción en `snake_case`. El número define el orden de aplicación.

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

- **Datos de prueba.** Trabajadores ficticios, marcaciones de ejemplo → `database/seeders/` con Faker.
- **Consultas de diagnóstico.** Van en `database/diagnostico/`, para correr en SSMS. Un `SELECT` dentro de una migración deja un cursor abierto y rompe la consulta siguiente.

Los **catálogos** sí van acá (sección 20 de `V001`): roles, regímenes laborales, tipos de papeleta, conceptos de justificación. No son datos de prueba — sin ellos el sistema no arranca.
