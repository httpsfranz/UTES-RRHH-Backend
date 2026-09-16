# Integración de SQL Server con Laravel

**Sistema de Control de Asistencia — Red de Salud Trujillo**

Guía de instalación para cada integrante del equipo. Al terminar, todos tendrán el mismo esquema, reconstruible con un solo comando, y una forma segura de propagar cambios de base de datos por Git.

Sin Docker. Con el SQL Server que ya tienen instalado en Windows.

---

## Cómo funciona esto, en un minuto

Hoy cada uno corre el `.sql` a mano en su máquina. El problema no es que funcione o no: es que **no hay forma de saber quién tiene qué**. Si mañana agregas una columna, tú la tienes y los demás no, y nadie se entera hasta que a alguien le revienta un endpoint.

La solución no es compartir una base de datos. Es dejar de tratar la base como algo que se instala y empezar a tratarla como algo que **se reconstruye desde el repositorio**:

```
database/sql/V001__esquema_base.sql        ← el esquema vive acá, en T-SQL
database/sql/V002__lo_que_venga.sql        ← cada cambio es un archivo NUEVO
        ↓
php artisan migrate                        ← aplica lo que falte, en orden
        ↓
tabla dbo.SqlScriptAplicado                ← deja constancia con SHA-256
```

Siguen escribiendo T-SQL, siguen usando SSMS para consultar. Lo único que cambia es que **nadie vuelve a crear ni modificar estructura a mano**.

Por qué no migraciones de Laravel en PHP: el esquema usa esquemas de SQL Server (`Organizacion`, `Asistencia`, `Seguridad`...), índices únicos filtrados, `CHECK` con listas de valores y vistas. Nada de eso existe en el schema builder de Laravel. Migrarlo a PHP significaría envolver el mismo T-SQL en `DB::statement('...')` — el mismo código, pero sin resaltado de sintaxis y sin poder probarlo en SSMS. Lo valioso de las migraciones nunca fue el PHP: fue llevar la cuenta de qué se aplicó dónde. Eso se conserva entero.

---

## PARTE A — Preparación del repositorio

> Esto lo hace **una sola persona, una sola vez**, y lo sube a `develop`. El resto del equipo salta directo a la Parte B.

### A.1 — Copiar los archivos

Desde la raíz del proyecto Laravel:

```
proyecto-laravel/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── EstadoEsquema.php                          ← nuevo
│   └── Support/
│       └── RunsSqlFile.php                                ← nuevo
├── database/
│   ├── sql/                                               ← carpeta nueva
│   │   ├── V001__esquema_base.sql
│   │   └── R001__rollback_esquema_base.sql
│   ├── diagnostico/                                       ← carpeta nueva
│   │   └── validacion_esquema.sql
│   └── migrations/
│       └── 2026_09_16_000001_v001_esquema_base.php        ← nuevo
├── docs/
│   ├── GUIA_INTEGRACION_SQLSERVER_LARAVEL.md              ← este archivo
│   └── NOTA_MIGRACION_DESDE_V1.md
├── config/database.php                                    ← se edita (paso A.2)
└── .env.example                                           ← se reemplaza (paso A.3)
```

La carpeta `app/Support/` probablemente no exista todavía. Créala; Laravel autocarga cualquier cosa bajo `app/` por PSR-4, no hay que registrar nada.

`EstadoEsquema.php` tampoco necesita registro: Laravel 11 y 12 descubren solos los comandos en `app/Console/Commands/`.

**Qué se le hizo al script original** (por transparencia, está documentado también en la cabecera del `V001`):

| Cambio | Por qué |
|---|---|
| Se quitó `CREATE DATABASE` / `USE [DB_ControlAsistencia]` | Laravel se conecta a una base que ya existe. `USE` no puede ejecutarse desde PDO: cambiaría la base bajo los pies de la conexión. La base vacía la crea cada desarrollador una vez (paso B.2). |
| Se quitó la sección 22 *Validación final* → `database/diagnostico/` | Eran `SELECT` de diagnóstico. Un resultset sin consumir dentro de `PDO::exec()` deja el cursor abierto y hace fallar la siguiente consulta de la migración. Ahora se corren en SSMS cuando se quieran. |
| Se quitó la nota final de migración desde v1 → `docs/` | Documentación, no DDL. |
| **El DDL y los catálogos no se tocaron en ninguna línea** | Las 2 668 líneas de estructura y datos semilla son idénticas al original. |

### A.2 — Editar `config/database.php`

Dos cambios. Primero el motor por defecto:

```php
'default' => env('DB_CONNECTION', 'sqlsrv'),
```

Y en el array `'connections'`, el bloque `'sqlsrv'` — Laravel ya lo trae, solo hay que descomentar las dos últimas líneas. Está completo en `config/database.sqlsrv.snippet.php`:

```php
'sqlsrv' => [
    'driver'   => 'sqlsrv',
    'url'      => env('DB_URL'),
    'host'     => env('DB_HOST', 'localhost'),
    'port'     => env('DB_PORT', '1433'),
    'database' => env('DB_DATABASE', 'DB_ControlAsistencia'),
    'username' => env('DB_USERNAME', 'sa'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => 'utf8',
    'prefix'   => '',
    'prefix_indexes' => true,
    'schema'   => 'dbo',

    // ↓↓↓ estas dos vienen comentadas de fábrica. Descomentar. ↓↓↓
    'encrypt'                  => env('DB_ENCRYPT', 'yes'),
    'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
],
```

`'schema' => 'dbo'` es correcto y no hay que cambiarlo: es donde Laravel pone `migrations`, `jobs`, `cache` y `SqlScriptAplicado`. Las tablas del sistema viven en sus propios esquemas y cada modelo Eloquent los nombra completos (`Asistencia.AsistenciaDiaria`).

### A.3 — `.env.example` y `.gitignore`

Reemplaza tu `.env.example` por el que viene en esta entrega, y confirma que el `.gitignore` tiene:

```gitignore
/.env
/.env.backup
```

`.env` **nunca** se sube: cada uno tiene su contraseña de `sa` y su nombre de instancia. `.env.example` **sí** se sube: es la plantilla.

### A.4 — Commit

```bash
git checkout -b feature/esquema-versionado
git add app/Support app/Console/Commands database/sql database/diagnostico \
        database/migrations config/database.php .env.example docs/
git commit -m "feat(db): esquema base versionado como script SQL aplicado por migrate"
git push -u origin feature/esquema-versionado
```

PR a `develop` y que lo revise alguien más.

---

## PARTE B — Instalación en cada máquina

> Esto lo hacen **todos**, incluido quien hizo la Parte A. ~15 minutos.

### B.1 — Verificar los drivers

Ya los tienen instalados, pero conviene confirmar que PHP los ve. En CMD o PowerShell:

```bash
php -m | findstr sqlsrv
```

Debe imprimir exactamente dos líneas:

```
pdo_sqlsrv
sqlsrv
```

Si no aparece ninguna, las extensiones están descargadas pero no habilitadas en el `php.ini`. Si aparece solo una, falta habilitar la otra. Las dos son necesarias: Laravel usa `pdo_sqlsrv`, y `sqlsrv` es su dependencia.

> Verifica también que sea el **mismo PHP**. Si usas Laragon o XAMPP, `php -m` desde CMD puede estar leyendo otro PHP distinto al del servidor. Comprueba con `php --ini` que la ruta del `php.ini` cargado sea la que editaste.

### B.2 — Crear la base vacía

Abre SSMS, conéctate a tu instancia local, **New Query**, y ejecuta:

```sql
CREATE DATABASE DB_ControlAsistencia
    COLLATE Modern_Spanish_CI_AS;
GO
```

Eso es todo. No corras el script `.sql`: de eso se encarga `migrate`.

**El `COLLATE` no es decorativo.** Si cada uno deja el que trae su instalación (algunos tendrán `SQL_Latin1_General_CP1_CI_AS`, otros `Modern_Spanish_CI_AS`), las comparaciones de texto se comportan distinto entre máquinas. Con un collation sensible a mayúsculas, los `UNIQUE` sobre los códigos de catálogo dejarían pasar `'ADMIN'` y `'admin'` como dos filas diferentes, y `EessCodigo` perdería su garantía. Todos con el mismo collation, explícito.

> Si ya creaste la base antes de leer esto: `DROP DATABASE DB_ControlAsistencia;` y vuelve a crearla con el `COLLATE`. Todavía no hay nada que perder.

### B.3 — Configurar el `.env`

```bash
git pull origin develop
cp .env.example .env          # en CMD de Windows:  copy .env.example .env
composer install
php artisan key:generate
```

Abre `.env` y ajusta **solo estas tres líneas**:

```env
DB_HOST=127.0.0.1
DB_USERNAME=sa
DB_PASSWORD=TuContraseñaLocal
```

Según cómo tengas instalado SQL Server:

| Instalación | `DB_HOST` | `DB_PORT` |
|---|---|---|
| Instancia por defecto | `127.0.0.1` | `1433` |
| SQL Server Express | `localhost\SQLEXPRESS` | **comentar la línea** |
| Instancia con nombre | `localhost\NOMBREINSTANCIA` | **comentar la línea** |

Dos detalles que hacen perder tiempo:

- La barra invertida se escribe **tal cual** en `.env`: `localhost\SQLEXPRESS`. No se escapa, no lleva comillas.
- Con instancia nombrada hay que **comentar `DB_PORT`**. Si dejas host con instancia *y* puerto, el driver ignora la instancia y se va al 1433, donde no hay nadie escuchando.

Si prefieres autenticación de Windows en vez de `sa`, deja `DB_USERNAME` y `DB_PASSWORD` vacíos: el driver usa tu sesión de Windows. Funciona, pero es menos reproducible entre máquinas.

### B.4 — Probar la conexión antes de migrar

```bash
php artisan db:show
```

Si imprime el nombre de la base, la versión del motor y un conteo de tablas en cero, la conexión está lista. **Si da error, resuélvelo acá** — no sigas al paso B.5. La tabla de errores está al final de esta guía.

### B.5 — Construir el esquema

```bash
php artisan migrate
```

Salida esperada:

```
   INFO  Running migrations.

  2026_09_16_000001_v001_esquema_base ....................... 8.42s DONE
```

Entre 5 y 30 segundos según la máquina. Detrás de esa línea se ejecutaron 125 lotes: 13 esquemas, 60+ tablas, sus índices, los catálogos semilla (roles, regímenes laborales, tipos de papeleta, conceptos de justificación) y las vistas de apoyo.

Si falla, el mensaje te dice el número de lote, la línea aproximada del `.sql` y la sentencia exacta. Corriges y vuelves a correr `migrate`: el script es idempotente, retoma donde quedó.

### B.6 — Comprobar

```bash
php artisan sql:estado
```

```
  Base de datos : DB_ControlAsistencia
  Servidor      : 127.0.0.1

  +----------------------------+----------+---------------------+--------------+
  | Script                     | Estado   | Aplicado el         | SHA-256      |
  +----------------------------+----------+---------------------+--------------+
  | V001__esquema_base.sql     | aplicado | 2026-09-16 14:22:07 | 3f9a1c7e2b04 |
  +----------------------------+----------+---------------------+--------------+

  Esquema al día.
```

**Ese SHA-256 es la prueba que buscabas.** Compárenlo entre todos por el chat del grupo: si a los cinco les sale `3f9a1c7e2b04`, tienen literalmente el mismo esquema. Dejó de ser un acuerdo de confianza.

Y para el detalle fino, en SSMS: abre `database/diagnostico/validacion_esquema.sql`, selecciona `DB_ControlAsistencia`, F5. Te da el inventario de tablas por esquema, tablas sin PK, el mapa completo de FK y la verificación funcional de los 10 puntos del encargo.

---

## PARTE C — El día a día

Esta es la parte que evita que en dos semanas tengan cinco bases distintas.

### C.1 — Cuando alguien necesita cambiar el esquema

**Paso 1.** Crear un archivo nuevo. Nunca editar uno ya aplicado.

```sql
-- database/sql/V002__tolerancia_por_establecimiento.sql
ALTER TABLE Configuracion.Tolerancia
    ADD ToleranciaEessId INT NULL;
GO

ALTER TABLE Configuracion.Tolerancia
    ADD CONSTRAINT FK_Tolerancia_Eess FOREIGN KEY (ToleranciaEessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId);
GO
```

**Paso 2.** Su migración-cáscara. Tres líneas, copiando la de `V001` y cambiando el nombre del archivo:

```php
<?php
// database/migrations/2026_09_20_000002_v002_tolerancia_por_establecimiento.php
use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V002__tolerancia_por_establecimiento.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R002__rollback_tolerancia_por_establecimiento.sql');
        $this->olvidarSqlFile('V002__tolerancia_por_establecimiento.sql');
    }
};
```

**Paso 3.** Probar en local con `php artisan migrate`, commit, y PR. Nunca push directo a `develop`.

### C.2 — Cuando el resto jala los cambios

```bash
git pull
php artisan migrate
```

`migrate` detecta `V002`, la aplica, y no vuelve a tocar `V001`. Quien clone el repo hoy recibe `V001` y `V002` en orden, y termina con exactamente la misma base.

### C.3 — Reconstruir desde cero

Cuando tus datos de prueba quedaron hechos un desastre y quieres empezar limpio:

```bash
php artisan migrate:fresh
php artisan db:seed
```

> `migrate:fresh` no funciona bien con esquemas de SQL Server (intenta un `DROP` genérico que no los contempla). Si te da problemas, la alternativa infalible son tres líneas en SSMS:
>
> ```sql
> DROP DATABASE DB_ControlAsistencia;
> GO
> CREATE DATABASE DB_ControlAsistencia COLLATE Modern_Spanish_CI_AS;
> GO
> ```
>
> y luego `php artisan migrate`. Tarda 30 segundos y nunca falla.

### C.4 — Las reglas

Cuatro. Van al README del repo.

1. **Nadie crea ni modifica estructura desde SSMS.** Consultar, sí; `SELECT`, `UPDATE` de datos de prueba, todo lo que quieras. Pero ningún `CREATE TABLE` ni `ALTER TABLE` a mano. Esa columna que creas "rapidito" no existe para nadie más, y son dos horas buscando por qué al compañero le falla el endpoint.
2. **Un script aplicado es inmutable.** Si te equivocaste en `V002`, no lo corriges: creas `V003` que arregla. El checksum lo hace cumplir solo — si editas uno aplicado, la próxima migración aborta con un mensaje que explica qué pasó.
3. **Los catálogos van en el `.sql`, los datos de prueba en seeders.** Roles, regímenes laborales, tipos de papeleta y conceptos de justificación son parte del esquema: sin ellos el sistema no arranca, y ya están en la sección 20 de `V001`. Los trabajadores ficticios y las marcaciones de ejemplo van en `database/seeders/` con Faker, para poder borrarlos sin miedo.
4. **Una persona es dueña del esquema.** Todo PR que toque `database/sql/` pasa por ella. No es burocracia: es la única forma de que alguien tenga el modelo completo en la cabeza.

---

## PARTE D — Después del esquema: los modelos Eloquent

Esto ya no es infraestructura, es desarrollo, pero es lo siguiente que van a necesitar y conviene que quede claro desde ahora: **los modelos no se escriben a mano.**

```bash
composer require --dev reliese/laravel
php artisan vendor:publish --tag=reliese-models
php artisan code:models --schema=Personal
```

Lee la base ya construida y genera los modelos con sus relaciones a partir de las FK. Después ajustas lo que el generador no puede adivinar:

```php
namespace App\Models\Asistencia;

use Illuminate\Database\Eloquent\Model;

class AsistenciaDiaria extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Asistencia].[AsistenciaDiaria].
    protected $table = 'Asistencia.AsistenciaDiaria';

    protected $primaryKey = 'AsistenciaDiariaId';

    // El modelo no tiene created_at / updated_at: la trazabilidad va por
    // el esquema Auditoria. Sin esta línea, todo INSERT falla.
    public $timestamps = false;

    protected $casts = [
        'AsistenciaDiariaFecha'      => 'date',
        'AsistenciaDiariaHoraEntrada' => 'datetime',
        'AsistenciaDiariaHoraSalida'  => 'datetime',
    ];
}
```

Para las columnas `BIT` (`EstadoAsistenciaEsFalta`, `...EsDescontable`, todos los `...Estado`), `'casts' => 'boolean'`. Sin eso te llegan como `1` y `0` y el JSON de la API sale con enteros donde el frontend espera `true`/`false`.

Las vistas (`vw_Estructura`, `vw_FaltasJustificadas`, etc.) se consumen como modelos de solo lectura apuntando a la vista. No las repliques con joins de Eloquent: ya están resueltas y optimizadas en SQL.

---

## PARTE E — Errores frecuentes

| Mensaje | Causa | Solución |
|---|---|---|
| `could not find driver` | PHP no tiene las extensiones cargadas, o estás en otro PHP del que configuraste | `php --ini`, confirma la ruta del `php.ini` cargado y que tenga las dos líneas `extension=` |
| `SSL Provider: The certificate chain was issued by an authority that is not trusted` | ODBC Driver 18 cifra por defecto y tu SQL Server local tiene certificado autofirmado. **Es el error más común.** | `DB_TRUST_SERVER_CERTIFICATE=true` en `.env` y que las dos líneas estén descomentadas en `config/database.php` |
| `Login failed for user 'sa'` | La autenticación mixta está desactivada, o `sa` está deshabilitado | SSMS → clic derecho en el servidor → Properties → Security → *SQL Server and Windows Authentication mode* → reiniciar el servicio. Y en Security → Logins → sa → Status → Login: Enabled |
| `TCP Provider: No connection could be made` | TCP/IP deshabilitado en la instancia | SQL Server Configuration Manager → SQL Server Network Configuration → Protocols → TCP/IP → **Enabled** → reiniciar el servicio |
| Conecta desde SSMS pero no desde Laravel, con instancia nombrada | Dejaste `DB_HOST=localhost\SQLEXPRESS` **y** `DB_PORT=1433` | Comenta `DB_PORT`. Y arranca el servicio *SQL Server Browser* |
| `Cannot open database "DB_ControlAsistencia" requested by the login` | La base vacía no existe todavía | Paso B.2 |
| `Incorrect syntax near 'GO'` | El `.sql` se está ejecutando sin partir por lotes | Estás corriendo el script fuera del trait. Usa `php artisan migrate` |
| `CREATE VIEW must be the first statement in a query batch` | Se perdió un separador `GO` al editar un script | Cada `CREATE VIEW` necesita su `GO` inmediatamente antes |
| `El script 'V001...' fue MODIFICADO después de aplicarse` | Alguien editó un script ya aplicado — el checksum lo detectó | `git checkout database/sql/V001__esquema_base.sql` y el cambio va en un `V0XX` nuevo. **Funcionando como debe** |
| `SQLSTATE[IMSSP]: An invalid keyword 'Encrypt'` | ODBC Driver 13 o 17, que no conoce ese parámetro | Instala ODBC Driver 18, o quita `encrypt`/`trust_server_certificate` del config |

---

## Checklist

Antes de empezar a desarrollar:

- [ ] `php -m | findstr sqlsrv` imprime `pdo_sqlsrv` y `sqlsrv`
- [ ] Base creada con `COLLATE Modern_Spanish_CI_AS`
- [ ] `php artisan db:show` conecta
- [ ] `php artisan migrate` corrió sin errores
- [ ] `php artisan sql:estado` dice *Esquema al día*
- [ ] **Los cinco compararon el SHA-256 y les dio el mismo**
- [ ] `.env` en `.gitignore`, `.env.example` versionado
- [ ] Las cuatro reglas de C.4 en el README del repo
- [ ] Definido quién revisa los PR que tocan `database/sql/`

---

## Para el informe de prácticas

En el punto **3.1 — Arquitectura de datos** puedes sustentar este enfoque como *database-first con control de versiones de esquema*: la base de datos es la fuente de verdad, el ORM es consumidor, y cada cambio estructural queda como un artefacto inmutable, ordenado, verificable por checksum y trazable en el repositorio.

Es una decisión de ingeniería defendible, con criterio técnico explícito: el motor está fijado por la institución y el modelo explota características propietarias de SQL Server (esquemas, índices filtrados, restricciones nombradas) que un ORM no puede expresar sin degradarse. Si necesitaran portabilidad entre motores, la decisión correcta sería la contraria — y vale la pena decirlo así en el informe, porque muestra que la alternativa se evaluó en vez de descartarse por costumbre.
