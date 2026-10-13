<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Expansion de volumen, parte 1: la dotacion de personal de toda la Red. Cada establecimiento recibe la plantilla propia de
 * su nivel (un C.S. I-4 tiene unos 21 trabajadores; un P.S. I-1, 4; la sede, 15), con sus vinculos laborales, colegiaturas,
 * consentimiento y plantilla biometrica, los responsables del establecimiento y los usuarios que los operan.
 *
 * Usa VolumenBase. Deja la lista de trabajadores en $this->dotacion para las otras partes de la expansion.
 */
trait VolumenPersonal
{
    /** Condiciones que exigen codigo AIRHSP (CondicionLaboralRequiereAirhsp = 1 en el catalogo). */
    private const CONDICIONES_CON_AIRHSP = ['NOMBRADO', 'CONTRATADO', 'CAS', 'SERUMS_REM'];

    /**
     * Plantilla por nivel: [cargo, condicion, regimen, profesion|null, cantidad, modo]. El modo dice como se controla la
     * asistencia: 'prog' = programacion mensual de turnos; un codigo de horario = horario fijo asignado; 'ninguno' = sin
     * control de jornada (terceros).
     *
     * @return array<string,list<array{0:string,1:string,2:string,3:?string,4:int,5:string}>>
     */
    private function plantillasPorNivel(): array
    {
        $tecAdm = ['Técnico(a) Administrativo(a)', 'CAS', 'DL1057', 'TEC_ADM'];

        return [
            'CS_I4' => [
                ['Médico(a) Cirujano(a)', 'NOMBRADO', 'DL276', 'MEDICO', 2, 'prog'],
                ['Médico(a) Cirujano(a)', 'CONTRATADO', 'DL276', 'MEDICO', 1, 'prog'],
                ['Enfermero(a)', 'NOMBRADO', 'DL276', 'ENFERMERIA', 3, 'prog'],
                ['Enfermero(a)', 'CONTRATADO', 'DL276', 'ENFERMERIA', 2, 'prog'],
                ['Obstetra', 'NOMBRADO', 'DL276', 'OBSTETRICIA', 1, 'prog'],
                ['Obstetra', 'CAS', 'DL1057', 'OBSTETRICIA', 1, 'prog'],
                ['Odontólogo(a)', 'CONTRATADO', 'DL276', 'ODONTOLOGIA', 1, 'prog'],
                ['Psicólogo(a)', 'CAS', 'DL1057', 'PSICOLOGIA', 1, 'prog'],
                ['Nutricionista', 'SERUMS_REM', 'DL1153', 'NUTRICION', 1, 'prog'],
                ['Químico(a) Farmacéutico(a)', 'CAS', 'DL1057', 'FARMACIA', 1, 'prog'],
                ['Técnico(a) en Enfermería', 'NOMBRADO', 'DL276', 'TEC_ENF', 3, 'prog'],
                ['Técnico(a) en Enfermería', 'CAS', 'DL1057', 'TEC_ENF', 2, 'prog'],
                ['Técnico(a) de Laboratorio', 'CONTRATADO', 'DL276', 'TEC_LAB', 1, 'prog'],
                [...$tecAdm, 1, 'HOR-ESS-M'],
                ['Vigilante de Portería', 'TERCEROS', 'OTRO', null, 1, 'ninguno'],
            ],
            'CS_I3' => [
                ['Médico(a) Cirujano(a)', 'NOMBRADO', 'DL276', 'MEDICO', 1, 'prog'],
                ['Médico(a) Cirujano(a)', 'CONTRATADO', 'DL276', 'MEDICO', 1, 'prog'],
                ['Enfermero(a)', 'NOMBRADO', 'DL276', 'ENFERMERIA', 2, 'prog'],
                ['Enfermero(a)', 'CONTRATADO', 'DL276', 'ENFERMERIA', 1, 'prog'],
                ['Obstetra', 'NOMBRADO', 'DL276', 'OBSTETRICIA', 1, 'prog'],
                ['Obstetra', 'CAS', 'DL1057', 'OBSTETRICIA', 1, 'prog'],
                ['Odontólogo(a)', 'CONTRATADO', 'DL276', 'ODONTOLOGIA', 1, 'prog'],
                ['Nutricionista', 'SERUMS_REM', 'DL1153', 'NUTRICION', 1, 'prog'],
                ['Técnico(a) en Enfermería', 'NOMBRADO', 'DL276', 'TEC_ENF', 2, 'prog'],
                ['Técnico(a) en Enfermería', 'CAS', 'DL1057', 'TEC_ENF', 1, 'prog'],
                ['Técnico(a) de Laboratorio', 'CAS', 'DL1057', 'TEC_LAB', 1, 'prog'],
                [...$tecAdm, 1, 'HOR-ESS-M'],
                ['Vigilante de Portería', 'TERCEROS', 'OTRO', null, 1, 'ninguno'],
            ],
            'PS_I2' => [
                ['Enfermero(a)', 'NOMBRADO', 'DL276', 'ENFERMERIA', 1, 'prog'],
                ['Enfermero(a)', 'SERUMS_EQUIV', 'DL1153', 'ENFERMERIA', 1, 'prog'],
                ['Obstetra', 'CONTRATADO', 'DL276', 'OBSTETRICIA', 1, 'prog'],
                ['Médico(a) Cirujano(a)', 'SERUMS_REM', 'DL1153', 'MEDICO', 1, 'prog'],
                ['Técnico(a) en Enfermería', 'NOMBRADO', 'DL276', 'TEC_ENF', 1, 'prog'],
                ['Técnico(a) en Enfermería', 'CAS', 'DL1057', 'TEC_ENF', 1, 'prog'],
            ],
            'PS_I1' => [
                ['Enfermero(a)', 'SERUMS_REM', 'DL1153', 'ENFERMERIA', 1, 'prog'],
                ['Obstetra', 'CAS', 'DL1057', 'OBSTETRICIA', 1, 'prog'],
                ['Técnico(a) en Enfermería', 'NOMBRADO', 'DL276', 'TEC_ENF', 1, 'prog'],
                ['Técnico(a) en Enfermería', 'CAS', 'DL1057', 'TEC_ENF', 1, 'prog'],
            ],
            'SEDE' => [
                ['Contador(a)', 'NOMBRADO', 'DL276', 'CONTABILIDAD', 1, 'HOR-ADM-LV'],
                ['Abogado(a)', 'CAS', 'DL1057', 'DERECHO', 1, 'HOR-ADM-LV'],
                ['Analista de Recursos Humanos', 'CAS', 'DL1057', 'ADMIN', 3, 'HOR-ADM-LV'],
                ['Responsable de Control de Asistencia y Permanencia', 'CONTRATADO', 'DL276', 'ADMIN', 1, 'HOR-ADM-LV'],
                ['Técnico(a) Administrativo(a)', 'NOMBRADO', 'DL276', 'TEC_ADM', 2, 'HOR-ADM-LV'],
                ['Técnico(a) Administrativo(a)', 'CAS', 'DL1057', 'TEC_ADM', 2, 'HOR-ADM-LV'],
                ['Auxiliar Administrativo(a)', 'CAS', 'DL1057', 'SIN_PROF', 2, 'HOR-ADM-LV'],
                ['Vigilante de Portería', 'TERCEROS', 'OTRO', null, 3, 'ninguno'],
            ],
            'CSMC' => [
                ['Psicólogo(a)', 'CAS', 'DL1057', 'PSICOLOGIA', 3, 'HOR-CSMC-ADM'],
                ['Médico(a) Cirujano(a)', 'CAS', 'DL1057', 'MEDICO', 1, 'HOR-CSMC-ADM'],
                ['Enfermero(a)', 'CAS', 'DL1057', 'ENFERMERIA', 1, 'HOR-CSMC-ADM'],
                ['Técnico(a) Administrativo(a)', 'CAS', 'DL1057', 'TEC_ADM', 1, 'HOR-CSMC-ADM'],
                ['Auxiliar Administrativo(a)', 'CAS', 'DL1057', 'SIN_PROF', 1, 'HOR-CSMC-ADM'],
            ],
        ];
    }

    /** @return array<string,string> codigo de EESS => nivel de plantilla (P.S. y C.S. inactivos no se pueblan) */
    private function nivelesPorEstablecimiento(): array
    {
        return [
            'EESS-LE-01' => 'CS_I4', 'EESS-EP-01' => 'CS_I4', 'EESS-VL-01' => 'CS_I4',
            'EESS-FM-01' => 'CS_I3', 'EESS-EP-02' => 'CS_I3', 'EESS-HU-01' => 'CS_I3', 'EESS-LA-01' => 'CS_I3', 'EESS-MO-01' => 'CS_I3',
            'EESS-LE-02' => 'PS_I2', 'EESS-FM-02' => 'PS_I2', 'EESS-SA-01' => 'PS_I2',
            'EESS-LE-03' => 'PS_I1', 'EESS-EP-03' => 'PS_I1', 'EESS-VL-02' => 'PS_I1',
            'SEDE-RRHH' => 'SEDE', 'SEDE-CSMC' => 'CSMC',
        ];
    }

    /** Orden de la expansion de personal. */
    protected function volumenPersonal(): void
    {
        $this->volumenDispositivos();
        $this->volumenTrabajadoresYVinculos();
        $this->volumenColegiaturas();
        $this->volumenUsuarios();
        $this->volumenBiometria();
    }

    /** Lectores de los C.S. que aun no tenian: los P.S. y Laredo siguen sin reloj y usan el parte diario (RIT, Art. 21). */
    private function volumenDispositivos(): void
    {
        $eess = DB::table('Organizacion.EstablecimientoSalud')->pluck('EessId', 'EessCodigo')->all();

        // [eess, codigo, nombre, tipo, ubicacion, ip]
        $nuevos = [
            ['EESS-EP-02', 'DISP-EP-02', 'Reloj facial - C.S. Alto Trujillo', 'Facial', 'Ingreso de personal', '192.168.31.11'],
            ['EESS-HU-01', 'DISP-HU-01', 'Reloj facial - C.S. Huanchaco', 'Facial', 'Recepción', '192.168.50.11'],
            ['EESS-VL-01', 'DISP-VL-01', 'Reloj facial - C.S. Víctor Larco', 'Facial', 'Ingreso de personal', '192.168.60.11'],
            ['EESS-VL-01', 'DISP-VL-02', 'Lector de huella - Emergencia Víctor Larco', 'Huella dactilar', 'Área de emergencia', '192.168.60.12'],
            ['EESS-MO-01', 'DISP-MO-01', 'Reloj facial - C.S. Moche', 'Facial', 'Ingreso de personal', '192.168.70.11'],
            ['SEDE-CSMC', 'DISP-CSMC-01', 'Reloj facial - Salud Mental Comunitaria', 'Facial', 'Recepción', '192.168.10.31'],
        ];
        foreach ($nuevos as [$codigo, $dispositivo, $nombre, $tipo, $ubicacion, $ip]) {
            if (DB::table('Biometria.DispositivoMarcacion')->where('DispositivoMarcacionCodigo', $dispositivo)->exists()) {
                continue;
            }
            DB::table('Biometria.DispositivoMarcacion')->insert([
                'EessId' => $eess[$codigo], 'DispositivoMarcacionCodigo' => $dispositivo, 'DispositivoMarcacionNombre' => $nombre,
                'DispositivoMarcacionTipo' => $tipo, 'DispositivoMarcacionUbicacion' => $ubicacion, 'DispositivoMarcacionIp' => $ip,
                'DispositivoMarcacionEstado' => 1,
            ]);
        }
    }

    private function volumenTrabajadoresYVinculos(): void
    {
        $tipoDni = (int) DB::table('Personal.TipoDocumentoIdentidad')->where('TipoDocumentoIdentidadCodigo', 'DNI')->value('TipoDocumentoIdentidadId');
        $profesiones = DB::table('Personal.Profesion')->pluck('ProfesionId', 'ProfesionCodigo')->all();
        $cargos = DB::table('Personal.Cargo')->pluck('CargoId', 'CargoNombre')->all();
        $condiciones = DB::table('Personal.CondicionLaboral')->pluck('CondicionLaboralId', 'CondicionLaboralCodigo')->all();
        $regimenes = DB::table('Personal.RegimenLaboral')->pluck('RegimenLaboralId', 'RegimenLaboralCodigo')->all();
        $eessIds = DB::table('Organizacion.EstablecimientoSalud')->pluck('EessId', 'EessCodigo')->all();
        $distritos = DB::table('Organizacion.EstablecimientoSalud as e')->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')
            ->pluck('m.MicroredDistrito', 'e.EessCodigo')->all();
        $plantillas = $this->plantillasPorNivel();
        $femeninos = $this->nombresFemeninos();
        $masculinos = $this->nombresMasculinos();
        $segundos = $this->segundosNombres();
        $apellidos = $this->apellidos();

        $personas = [];
        $n = 0;
        foreach ($this->nivelesPorEstablecimiento() as $eess => $nivel) {
            foreach ($plantillas[$nivel] as [$cargo, $condicion, $regimen, $profesion, $cantidad, $modo]) {
                for ($i = 0; $i < $cantidad; $i++) {
                    $n++;
                    $esMujer = $this->azar("sexo{$n}") < 62;
                    $nombres = $this->elegir("nom{$n}", $esMujer ? $femeninos : $masculinos);
                    $segundo = $this->elegir("seg{$n}", $segundos);
                    $nombres = trim($nombres.' '.$segundo);
                    $paterno = $apellidos[($n * 7) % count($apellidos)];
                    $materno = $apellidos[($n * 11 + 5) % count($apellidos)];
                    if ($materno === $paterno) {
                        $materno = $apellidos[($n * 11 + 6) % count($apellidos)];
                    }
                    $personas[] = [
                        'n' => $n, 'eess' => $eess, 'cargo' => $cargo, 'condicion' => $condicion, 'regimen' => $regimen, 'profesion' => $profesion, 'modo' => $modo,
                        'sexo' => $esMujer ? 'F' : 'M', 'nombres' => $nombres, 'paterno' => $paterno, 'materno' => $materno,
                        'documento' => (string) (46000000 + $n), 'inicio' => $this->inicioDeVinculo($condicion, $n),
                    ];
                }
            }
        }

        $this->insertarPorLotes('Personal.Trabajador', array_map(fn (array $p) => [
            'TipoDocumentoIdentidadId' => $tipoDni,
            'ProfesionId' => $p['profesion'] === null ? null : $profesiones[$p['profesion']],
            'TrabajadorNumeroDocumento' => $p['documento'],
            'TrabajadorNombres' => $p['nombres'],
            'TrabajadorApellidoPaterno' => $p['paterno'],
            'TrabajadorApellidoMaterno' => $p['materno'],
            'TrabajadorSexo' => $p['sexo'],
            'TrabajadorFechaNacimiento' => sprintf('%d-%02d-%02d', 1964 + $this->azarEntre("nac{$p['n']}", 0, 36), 1 + $p['n'] % 12, 1 + ($p['n'] * 5) % 28),
            'TrabajadorCorreo' => $this->azar("mail{$p['n']}") < 60 ? Str::lower(Str::ascii(Str::before($p['nombres'], ' ').'.'.$p['paterno'])).$p['n'].'@ejemplo.pe' : null,
            'TrabajadorTelefono' => $this->azar("tel{$p['n']}") < 85 ? '99'.str_pad((string) (2000000 + $p['n'] * 137 % 7000000), 7, '0', STR_PAD_LEFT) : null,
            'TrabajadorDireccion' => $this->direccionDe($p['n'], $distritos[$p['eess']] ?? 'Trujillo'),
            'TrabajadorFechaRegistro' => '2026-09-01 08:00:00',
            'TrabajadorEstado' => 1,
        ], $personas));

        $trabajadorPorDocumento = DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', 'like', '46%')->pluck('TrabajadorId', 'TrabajadorNumeroDocumento')->all();

        $this->insertarPorLotes('Personal.VinculoLaboral', array_map(fn (array $p) => [
            'TrabajadorId' => $trabajadorPorDocumento[$p['documento']],
            'EessId' => $eessIds[$p['eess']],
            'CargoId' => $cargos[$p['cargo']],
            'CondicionLaboralId' => $condiciones[$p['condicion']],
            'RegimenLaboralId' => $regimenes[$p['regimen']],
            'VinculoLaboralCodigo' => 'VL-'.(1000 + $p['n']),
            'VinculoLaboralCodigoAirhsp' => in_array($p['condicion'], self::CONDICIONES_CON_AIRHSP, true) ? '2'.str_pad((string) $p['n'], 5, '0', STR_PAD_LEFT) : null,
            'VinculoLaboralNumeroPlaza' => $p['condicion'] === 'NOMBRADO' ? 'P-2'.str_pad((string) $p['n'], 3, '0', STR_PAD_LEFT) : null,
            'VinculoLaboralFechaInicio' => $p['inicio'],
        ], $personas));

        $vinculoPorCodigo = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', 'like', 'VL-1%')->pluck('VinculoLaboralId', 'VinculoLaboralCodigo')->all();
        foreach ($personas as $p) {
            $this->dotacion[] = $p + [
                'vinculo' => (int) $vinculoPorCodigo['VL-'.(1000 + $p['n'])],
                'trabajador' => (int) $trabajadorPorDocumento[$p['documento']],
                'codigo' => 'VL-'.(1000 + $p['n']),
                'eess_id' => (int) $eessIds[$p['eess']],
                'rol' => null,
            ];
        }
    }

    /** Fecha de ingreso verosimil segun la condicion laboral (el SERUMS empieza en marzo o abril de 2026). */
    private function inicioDeVinculo(string $condicion, int $n): string
    {
        $entre = fn (string $desde, string $hasta) => date('Y-m-d', strtotime($desde) + ($this->azarEntre("ini{$n}", 0, intdiv(strtotime($hasta) - strtotime($desde), 86400))) * 86400);

        return match ($condicion) {
            'NOMBRADO' => $entre('1999-01-04', '2019-12-31'),
            'CONTRATADO' => $entre('2015-02-02', '2024-06-30'),
            'CAS' => $entre('2018-03-01', '2025-06-30'),
            'SERUMS_REM', 'SERUMS_EQUIV' => $n % 2 === 0 ? '2026-03-01' : '2026-04-01',
            'TERCEROS' => $entre('2022-01-03', '2025-08-01'),
            default => '2024-01-02',
        };
    }

    private function volumenColegiaturas(): void
    {
        $colegios = DB::table('Personal.ColegiaturaTipo as c')->join('Personal.Profesion as p', 'p.ProfesionId', '=', 'c.ProfesionId')
            ->pluck('c.ColegiaturaTipoId', 'p.ProfesionCodigo')->all();

        $filas = [];
        foreach ($this->dotacion as $p) {
            if ($p['profesion'] === null || ! isset($colegios[$p['profesion']])) {
                continue;
            }
            $vencida = $this->azar("colvenc{$p['n']}") < 8;
            $filas[] = [
                'TrabajadorId' => $p['trabajador'],
                'ColegiaturaTipoId' => $colegios[$p['profesion']],
                'DocumentoSustentoId' => null,
                'ColegiaturaNumero' => (string) (20000 + $p['n']),
                'ColegiaturaFechaColegiatura' => date('Y-m-d', strtotime($p['inicio'].' -'.$this->azarEntre("colfec{$p['n']}", 200, 1500).' days')),
                'ColegiaturaFechaHabilitacion' => $vencida ? '2025-07-01' : '2026-01-01',
                'ColegiaturaFechaVencimiento' => $vencida ? '2026-06-30' : '2026-12-31',
                'ColegiaturaEsHabilitado' => $vencida ? 0 : 1,
                'ColegiaturaEsPrincipal' => 1,
                'ColegiaturaObservacion' => $vencida ? 'Pendiente de renovar la habilitación' : null,
            ];
        }
        $this->insertarPorLotes('Personal.Colegiatura', $filas);
    }

    /**
     * Cada establecimiento designa a su jefe, a quien lleva el control de asistencia y a quien programa los turnos (la sede, a
     * su responsable de personal); esas personas, y algunos trabajadores mas, tienen usuario.
     */
    private function volumenUsuarios(): void
    {
        $designaciones = [
            'JEFE_EESS' => ['Médico(a) Cirujano(a)', 'Contador(a)', 'Psicólogo(a)'],
            'RESP_ASISTENCIA' => ['Técnico(a) Administrativo(a)', 'Responsable de Control de Asistencia y Permanencia', 'Técnico(a) en Enfermería'],
            'RESP_PROGRAMACION' => ['Enfermero(a)'],
        ];
        $porEstablecimiento = [];
        foreach ($this->dotacion as $i => $p) {
            $porEstablecimiento[$p['eess']][] = $i;
        }
        foreach ($porEstablecimiento as $indices) {
            foreach ($designaciones as $rol => $cargos) {
                foreach ($cargos as $cargo) {
                    $elegido = collect($indices)->first(fn ($i) => $this->dotacion[$i]['cargo'] === $cargo && $this->dotacion[$i]['rol'] === null && $this->dotacion[$i]['condicion'] !== 'SERUMS_EQUIV');
                    if ($elegido !== null) {
                        $this->dotacion[$elegido]['rol'] = $rol;
                        break;
                    }
                }
            }
        }

        $usados = DB::table('Seguridad.Usuario')->pluck('UsuarioNombre')->flip()->all();
        $filas = [];
        foreach ($this->dotacion as $i => $p) {
            // Responsables y un 6 % de los demas (los que solo consultan su propia asistencia).
            if ($p['rol'] === null && ($p['modo'] === 'ninguno' || $this->azar("usu{$p['n']}") >= 6)) {
                continue;
            }
            $nombre = $this->usuarioDe($p['nombres'], $p['paterno']);
            $nombre = isset($usados[$nombre]) ? $nombre.$p['n'] : $nombre;
            $usados[$nombre] = true;
            $this->dotacion[$i]['usuario'] = $nombre;
            $filas[] = [
                'TrabajadorId' => $p['trabajador'], 'UsuarioNombre' => $nombre, 'UsuarioPasswordHash' => self::VOLUMEN_HASH,
                'UsuarioCorreo' => $nombre.'@ejemplo.pe', 'UsuarioFechaCreacion' => '2026-07-01 09:00:00', 'UsuarioEstado' => 1,
            ];
        }
        $this->insertarPorLotes('Seguridad.Usuario', $filas);
        $this->usuariosCache = [];

        // Roles y ambito: el que designa el establecimiento opera su EESS; el jefe del C.S. principal de cada microred, su microred.
        $roles = DB::table('Seguridad.Rol')->pluck('RolId', 'RolCodigo')->all();
        $microredDe = DB::table('Organizacion.EstablecimientoSalud')->pluck('MicroredId', 'EessId')->all();
        $usuarioPorNombre = DB::table('Seguridad.Usuario')->pluck('UsuarioId', 'UsuarioNombre')->all();
        $microredesAtendidas = [];
        $rolesFilas = [];
        $ambitos = [];
        foreach ($this->dotacion as $p) {
            if (! isset($p['usuario'])) {
                continue;
            }
            $usuario = (int) $usuarioPorNombre[$p['usuario']];
            $rolesDelUsuario = match ($p['rol']) {
                'JEFE_EESS', 'RESP_ASISTENCIA' => ['RESP_EESS', 'TRABAJADOR'],
                'RESP_PROGRAMACION' => ['PROGRAMADOR', 'TRABAJADOR'],
                default => ['TRABAJADOR'],
            };
            foreach ($rolesDelUsuario as $rol) {
                $rolesFilas[] = ['UsuarioId' => $usuario, 'RolId' => $roles[$rol], 'UsuarioRolFechaInicio' => '2026-07-01', 'UsuarioRolFechaFin' => null, 'UsuarioRolEstado' => 1];
            }
            $ambitos[] = ['UsuarioId' => $usuario, 'MicroredId' => null, 'EessId' => $p['eess_id'], 'UsuarioAmbitoEstado' => 1];
            // Jefe de microred: el primer jefe de EESS de cada microred (rol adicional con ambito de toda la microred).
            $microred = (int) $microredDe[$p['eess_id']];
            if ($p['rol'] === 'JEFE_EESS' && ! isset($microredesAtendidas[$microred]) && str_starts_with($p['eess'], 'EESS-')) {
                $microredesAtendidas[$microred] = true;
                $rolesFilas[] = ['UsuarioId' => $usuario, 'RolId' => $roles['JEFE_MICRORED'], 'UsuarioRolFechaInicio' => '2026-07-01', 'UsuarioRolFechaFin' => null, 'UsuarioRolEstado' => 1];
                $ambitos[] = ['UsuarioId' => $usuario, 'MicroredId' => $microred, 'EessId' => null, 'UsuarioAmbitoEstado' => 1];
            }
        }
        $this->insertarPorLotes('Seguridad.UsuarioRol', $rolesFilas);
        $this->insertarPorLotes('Seguridad.UsuarioAmbito', $ambitos);
    }

    /**
     * Consentimiento de todos (tres lo revocaron), plantilla facial de la mayoria (los demas siguen sin enrolar) y algunas
     * autorizaciones de otro metodo de marcacion (RIT, Art. 21).
     */
    private function volumenBiometria(): void
    {
        $metodos = DB::table('Biometria.MetodoMarcacion')->pluck('MetodoMarcacionId', 'MetodoMarcacionCodigo')->all();
        $revocan = [];

        $consentimientos = [];
        foreach ($this->dotacion as $p) {
            $consentimientos[] = [
                'TrabajadorId' => $p['trabajador'], 'ConsentimientoBiometricoFecha' => sprintf('2026-03-%02d %02d:%02d:00', 2 + $p['n'] % 24, 8 + $p['n'] % 6, ($p['n'] * 7) % 60),
                'ConsentimientoBiometricoAceptado' => 1, 'ConsentimientoBiometricoVersion' => 'v1.0',
            ];
            if ($p['modo'] !== 'ninguno' && $p['n'] % 61 === 0) {
                $revocan[$p['trabajador']] = true;
                $consentimientos[] = [
                    'TrabajadorId' => $p['trabajador'], 'ConsentimientoBiometricoFecha' => '2026-08-'.sprintf('%02d', 3 + $p['n'] % 20).' 11:00:00',
                    'ConsentimientoBiometricoAceptado' => 0, 'ConsentimientoBiometricoVersion' => 'v1.0',
                ];
            }
        }
        // El historial se inserta en orden cronologico: el consentimiento vigente es el ultimo de cada trabajador.
        usort($consentimientos, fn ($a, $b) => strcmp($a['ConsentimientoBiometricoFecha'], $b['ConsentimientoBiometricoFecha']));
        $this->insertarPorLotes('Biometria.ConsentimientoBiometrico', $consentimientos);

        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno' || $this->azar("plant{$p['n']}") >= 88) {
                continue;
            }
            $hex = bin2hex(hash('sha256', "plantilla-volumen-{$p['documento']}", true));
            DB::table('Biometria.PlantillaBiometrica')->insert([
                'TrabajadorId' => $p['trabajador'], 'PlantillaBiometricaTipo' => 'ROSTRO', 'PlantillaBiometricaDedo' => null,
                'PlantillaBiometricaFechaRegistro' => '2026-03-10 10:00:00',
                'PlantillaBiometricaEstado' => isset($revocan[$p['trabajador']]) ? 0 : 1,
                'PlantillaBiometricaReferencia' => DB::raw("CONVERT(VARBINARY(MAX), '{$hex}', 2)"),
            ]);
        }

        // Autorizaciones de otro metodo: el personal de portería y unos pocos mas, con metodo manual o tarjeta.
        $autorizaciones = [];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno' || $p['n'] % 37 === 0) {
                $metodo = $p['modo'] === 'ninguno' ? 'MANUAL' : 'TARJETA';
                $autorizaciones[] = [
                    'TrabajadorId' => $p['trabajador'], 'MetodoMarcacionId' => $metodos[$metodo], 'AutorizacionMetodoFechaInicio' => '2026-01-02',
                    'AutorizacionMetodoFechaFin' => $p['n'] % 74 === 0 ? '2026-06-30' : null, 'AutorizacionMetodoEstado' => 1,
                ];
            }
        }
        $this->insertarPorLotes('Biometria.AutorizacionMetodo', $autorizaciones);
    }
}
