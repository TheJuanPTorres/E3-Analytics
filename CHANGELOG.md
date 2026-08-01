# Changelog — E3 Analytics Dashboard

## 1.3.3

### Columnas demográficas en el export de País

Quince campos del formulario de registro, en este orden, después de "Registrado
en el período":

Género · Rango de edad · Departamento · Organización · Comunidad indígena (sí/no)
· Nombre de la comunidad · Rol · Tipo de perfil · Tipo de perfil (otro) · Formato
de contenido · Cómo nos conoció · Expectativas · Propósito

Y dos detrás del filtro `e3a_export_include_contact_pii` (default `true`):
**Documento de identidad** y **Teléfono**.

La hoja pasa de 27 a **38 columnas** (36 con el filtro apagado).

**Celdas vacías es lo correcto.** El formulario cambió con el tiempo: de ~3.446
usuarios solo ~748 tienen `gender_lms` y ~598 `department_lms`. Un hueco
significa "se registró antes de que el campo existiera", que es un dato. No se
rellena con valores por defecto ni con "N/A".

**Cuatro columnas se movieron, no se duplicaron.** `gender_lms`, `age_range_lms`,
`profile_type_lms` y `profile_type_other_lms` ya existían como columnas sueltas
con encabezado en inglés. Ahora forman parte del bloque demográfico, con
encabezado en español y una sola columna por `meta_key`. `age_min`, `age_max` y
`age_midpoint`, que se derivan de "Rango de edad", quedan pegadas a ella.

**Cero consultas nuevas.** El batch de `usermeta` ya era una sola query por cada
2.000 usuarios: solo se alargó su `IN`. Medido: 6 consultas antes, 6 después.
Con el filtro de PII apagado, esas dos claves ni siquiera se leen de la base.

### Contacto identificatorio detrás de un filtro

Un documento de identidad y un teléfono personal identifican a la persona de
forma directa, y este archivo **sale del servidor**: correo, WhatsApp, un Drive
compartido. Cada copia es una fuga que ya no se controla.

```php
add_filter( 'e3a_export_include_contact_pii', '__return_false' );
```

Default `true` por decisión explícita del proyecto, no por descuido.

### MetaScan: cruce `country_lms` vs `_pais`

La herramienta temporal reporta ahora cuántos usuarios tiene cada sistema de
país, cuántos comparten los dos, en cuántos coinciden los valores, el top 10 de
cada uno y ejemplos donde difieren.

Si `_pais` tiene usuarios que `country_lms` no tiene, el análisis por país los
está perdiendo. **Este bloque solo mide: no cambia la lógica de resolución.**

## 1.3.2

### Corregido: "Recurrentes" daba 0 en todos los cursos, siempre

Las columnas **Nuevos** y **Recurrentes** de "Detalle por curso" no medían lo que
sus nombres decían.

- **Definición anterior:** "Nuevos" = primera inscripción de ese usuario **a ese
  curso**. "Recurrentes" = re-inscripción al mismo curso. Como Tutor LMS crea un
  solo registro por par usuario-curso, la re-inscripción no ocurre nunca y la
  columna **daba 0 en todos los cursos, en todos los períodos**. La serie
  "Recurrentes" del gráfico era una línea plana en cero.
- **Definición nueva:** "Nuevos (registrados en el período)" = el usuario se
  registró en el sitio **dentro del período** y se inscribió al curso durante el
  período. "Ya registrados antes" = ya estaba registrado de antes.

No era un desfase de fechas ni una comparación mal armada: la comparación
anterior era coherente (hora local contra hora local). Era la definición la que
respondía otra pregunta. **Ningún otro KPI estaba afectado.**

Se aplicó a los cuatro consumidores: la tabla del dashboard, el gráfico "Nuevos
vs Recurrentes", la hoja `Cursos` del export (columnas
`nuevos_registrados_en_periodo` y `ya_registrados_antes`) y la ventana anterior.

**Identidad garantizada:** para cada curso, Nuevos + Ya registrados antes =
inscripciones del curso en el período. Se cumple por construcción.

### Columna "Registrado en el período"

Nueva en los dos exports de usuarios, con valores `sí` / `no`:

- **`ProgresoPorCurso`** (export "Detalle por curso"), junto a "Fecha de registro".
- **Export de País**, en la misma posición.

Un usuario borrado deja su inscripción huérfana y cae en `no`: se registró en
algún momento del pasado, no dentro del período.

### La definición vive en un solo lugar

`UsersRepository::ids_registered_between()` es **la** definición de "registrado en
el período" para todo el plugin, con límites UTC porque `user_registered` está en
UTC. Tres consumidores la usan. Cualquier consumidor nuevo debe llamarla en vez
de reimplementarla.

### Costo

- **Dashboard: cero queries netas.** Se eliminó
  `EnrollmentsRepository::first_enrollment_map_until()`, que quedó sin llamadores,
  y se agregó la del conjunto de registrados. 12 consultas antes, 12 después.
  Además libera memoria: el método viejo construía un array con **todos** los
  pares usuario-curso de la historia; el nuevo, una lista de IDs del período.
- **Export "Detalle por curso": +1 query**, por la columna nueva de
  `ProgresoPorCurso`.
- **Export de País: sin costo.** La consulta de registrados ya se ejecutaba para
  armar el universo de usuarios y su resultado se descartaba; ahora se conserva.

Se eliminaron también dos contadores internos, `previous_first_time_enroll` y
`previous_returning_enroll`, que se incrementaban y nunca se leían.

### Temporal: descubridor de `meta_key`

`?page=e3-analytics-dashboard&e3a_scan=metakeys`, solo para administradores.
Lista los `meta_key` de `wp_usermeta` con su frecuencia y un valor de ejemplo,
para decidir qué campos demográficos van al export de País. Los valores de claves
que parecen contener secretos salen como `[redactado]`. **Se elimina en el release
que agregue esas columnas.**

## 1.3.1

### Export "Detalle por curso": progreso por curso, y una sola tabla

La tarjeta **Detalle por curso** exportaba siete hojas cuando la clienta pedía
exportar una tabla, y su hoja de usuarios reportaba la actividad **agregada**: un
alumno con progreso en dos cursos aparecía en una sola fila con promedios.

- **Hoja nueva `ProgresoPorCurso`** — una fila por par alumno-curso, con curso,
  fecha de inscripción, progreso y estado de completación. 11 columnas, ordenada
  por alumno y después por curso.
- **`courses_detail` deja de emitir siete hojas.** Ahora son tres: `Resumen`,
  `Cursos` y `ProgresoPorCurso`. Las otras cinco keys que comparten esa rama
  (`performance`, `chart_new_vs_returning`, `top_courses`, `insights`,
  `retention`) no cambian.
- **`UsuariosActivos` pasa a llamarse `ResumenPorUsuario`** en los dos exports
  que la generan. Mismas columnas, mismos datos.
- **Encabezados en español y explícitos** en las dos hojas. En particular,
  "Completado (estado actual)" y "Progreso promedio % (estado actual)": esa
  columna informa el estado actual del alumno en el curso, **no** si completó
  dentro del período consultado.

**Sin consultas nuevas.** El detalle por curso ya se calculaba y se descartaba al
agregar por usuario. Medido con 3.081 inscripciones sintéticas: las cinco keys
sin cambios mantienen su conteo exacto de consultas, y `courses_detail` baja de
15 a **14** porque deja de pedir la hoja de inscripciones.

La hoja crece de ~2.074 filas (una por alumno) a ~3.081 (una por par).

## 1.3.0 — release

Consolida el trabajo de B1 a B4. La funcionalidad visible es el **selector de
período con rango personalizado**; el resto es la corrección de los defectos que
aparecieron al construirlo.

### Lo que ve la clienta

- **Selector de período**: 10 presets (7/30/90/365 días, este mes, mes pasado,
  este trimestre, este año, año pasado, histórico) más **rango personalizado**
  con dos selectores de fecha nativos.
- **Los registros del día ya no se pierden.** `wp_users.user_registered` lo
  escribe WordPress en UTC y el plugin lo comparaba contra límites en hora local.
  Con el offset −5 del sitio, todo usuario registrado después de las 19:00 hora
  local quedaba contado en el día siguiente y era invisible en el dashboard hasta
  la jornada siguiente. Pasaba todos los días.
- **"Tasa de actividad" deja de superar el 100%.** Mezclaba dos poblaciones
  distintas; medido en producción daba 171,0% en 30 días. Ahora es lo que su
  propia descripción siempre prometió, y el índice de salud baja en consecuencia.
- **Completación simplificada**: un curso al 100% de progreso cuenta como
  completado. El filtro de quizzes de retroalimentación se eliminó tras medir que
  no rechazaba ninguna inscripción en 3.071 pares curso-usuario.
- **Comparaciones sin base** muestran un guion en lugar de "+100%".
- **Períodos sin datos** muestran "Sin datos en este período" y ya no disparan la
  alarma roja de abandono.
- **Retención y DAU/MAU** quedan etiquetadas: no responden al filtro de fechas.

### Interno

- `DatePeriod` resuelve siempre en días calendario, con límites locales y sus
  equivalentes UTC. Se eliminan la rama legacy, el flag de modo
  (`E3A_DATE_MODE`, `e3a_date_mode`, filtro `e3a_date_mode`) y toda la
  matemática basada en `current_time('timestamp')` + `date_i18n()` + `strtotime()`
  para construir literales SQL.
- El período viaja como un escalar `period`. `Admin\Page::read_period()` es el
  único lector del request.
- Se elimina la herramienta de diagnóstico y la página de Configuración.
- `uninstall.php` nuevo: borra las opciones huérfanas y los transients.
- Headers `Requires PHP: 8.1`, `Requires at least`, `Text Domain`, `License`.
  Sin `Requires PHP`, WordPress dejaba instalar el plugin en PHP viejo y el fatal
  tumbaba el sitio entero.

### Filtros disponibles

| Filtro | Default | Qué hace |
|--------|---------|----------|
| `e3a_enrollment_post_type` | `tutor_enrolled` | Post type de las inscripciones |
| `e3a_max_custom_range_days` | `3650` | Tope del rango personalizado, en días |
| `e3a_label_date_format` | `j M Y` | Formato de las etiquetas legibles |
| `e3a_export_excel` | — | Habilita el export del dashboard |
| `e3a_export_country_users` | — | Habilita el export de usuarios por país |

### Formato de `period_key`

`7` | `30` | `90` | `365` | `this_month` | `last_month` | `this_quarter` |
`this_year` | `last_year` | `all` | `YYYY-MM-DD..YYYY-MM-DD`

El rango personalizado se guarda **ya normalizado**: si se recortó por el tope o
porque la fecha final era futura, la clave refleja el rango efectivo. Cualquier
otro valor cae a `30` e informa el motivo en la clave `notice`.

### Pendientes conocidos, NO corregidos

Ninguno de estos se tocó en este ciclo. Quedan documentados para la próxima tanda.

1. **`ExportService.php:259` — `GROUP BY` inválido.** Selecciona `post_parent` y
   `post_date` agrupando solo por `post_author`. **Devuelve datos silenciosamente
   incorrectos**: este sitio no tiene `ONLY_FULL_GROUP_BY` en su `sql_mode`, así
   que MySQL elige una fila arbitraria en lugar de fallar. En un servidor con la
   configuración por defecto de MySQL 5.7+, el export `first_time_enrollments`
   directamente falla con error 1055.
2. **Fuga de usermeta en los export.** `ExportService::user_full_row()`,
   `maybe_add_user_meta_sheet()` y `CountryUsersExportService::flatten_user_meta_json()`
   vuelcan `get_user_meta()` completo, sin allowlist. Puede incluir tokens de
   sesión, secretos de 2FA y claves de otros plugins, en un archivo que sale del
   servidor.
3. **Semántica de la retención.** Las ventanas están ancladas a "ahora" con techo
   de 365 días, así que las tasas de dos períodos distintos no son comparables.
   Hoy solo está etiquetado en la interfaz.
4. **N+1 en la detección de completación.** `is_course_completed()` y
   `course_progress_percent()` se llaman por cada par curso-usuario. `period=all`
   son ~7.000 queries y 2,8 s.
5. **Repositorios sin `LIMIT`.** `EnrollmentsRepository::rows_between()` y
   `first_enrollment_map_until()` cargan todo en memoria, y `Xlsx::sheet_xml()`
   arma el XML completo como string antes de escribirlo.

### Limitación conocida

Los presets relativos (`this_month`, `this_quarter`, `this_year`, `all`) se
resuelven contra "hoy". Un export disparado después de que la pantalla se
renderizó, cruzando la medianoche, resuelve una ventana distinta a la mostrada.
Está mitigado: el rango efectivo va en el nombre del archivo y en la fila `Rango`
de la hoja Resumen.

## 1.3.0-b3

### Selector de período con rango personalizado

Lo que pidió la clienta al principio. El selector pasa de 5 a 10 presets más un
rango personalizado con dos `<input type="date">` nativos.

| Preset | Etiqueta |
|--------|----------|
| `7` / `30` / `90` / `365` | Últimos N días |
| `this_month` / `last_month` | Este mes / Mes pasado |
| `this_quarter` | Este trimestre |
| `this_year` / `last_year` | Este año / Año pasado |
| `all` | Histórico completo |

`365` pasa de "Últimos 12 meses" a "Últimos 365 días": junto a "Este año" y "Año
pasado", que sí son unidades de calendario, la etiqueta anterior se volvía
ambigua.

**El período sigue viajando como un escalar.** El formulario manda `e3a_from` y
`e3a_to`; `Page::read_period()` los compone en `2026-03-01..2026-04-15` y de ahí
en más circula como `period`, igual que `30`. Ni las URLs de navegación ni las de
export llevan parámetros nuevos.

**Sin JS también funciona.** Los dos inputs de fecha se renderizan siempre
visibles; el JS solo los oculta cuando el preset no es "Rango personalizado". El
submit es el botón "Actualizar" de un `<form method="get">`.

### Tope del rango: 730 → 3650 días

La historia completa del sitio son 1.437 días y `period=all` tarda 2,79 s con
7.064 queries contra 300 s de `max_execution_time`. Con 730 la clienta no podía
seleccionar a mano su propia historia: el tope dejaba de proteger y pasaba a ser
una regresión funcional. Filtrable con `e3a_max_custom_range_days`.

### Etiquetas de fecha con formato explícito

El sitio tiene `date_format = 'm/d/Y'`, con el que un rango se leería
"03/01/2026 – 04/15/2026" — para un lector hispanohablante, 3 de enero a 15 de
abril. Esa cadena viaja al nombre del archivo de export y a la hoja Resumen, así
que la ambigüedad no era solo de pantalla.

`label` y `prev_label` usan ahora `'j M Y'` ("1 mar 2026 – 15 abr 2026"),
filtrable con `e3a_label_date_format`. Las tres closures `$fmt_date`/`$fmt_range`
duplicadas en las vistas desaparecen.

`prev_label` pasa a ser visible junto a los indicadores de crecimiento: con
rangos arbitrarios, contra qué se compara ya no es adivinable.

### Propagación del período

- Las 3 URLs de navegación pasan de concatenación cruda a `add_query_arg` sobre
  un array central de estado. Antes **perdían `course_id`, `bucket` y `q`** al
  cambiar de pestaña.
- Los 5 constructores de URL de export parten del mismo array.
- Los **10** nombres de archivo de `ExportService` incluyen el `period_key`. El
  sanitizador de `Xlsx` preserva `-` y `.`, así que
  `2026-03-01..2026-04-15` sobrevive intacto (verificado generando un XLSX real).
- La fila `Período` de la hoja Resumen lleva la etiqueta legible; `Rango`, las
  fechas.

### Estados vacíos y avisos

- Con `current_enrollments == 0` se muestra "Sin datos en este período" en lugar
  de las tasas, y **no** se pinta el banner rojo de abandono. Antes, un rango de
  un solo día en una semana floja daba `dropout_rate = 100` y disparaba una
  alarma falsa.
- `notice` de `DatePeriod` se muestra como `notice notice-warning` en las 3
  vistas: rango inválido que cayó al default, rango recortado por el tope, fecha
  final futura recortada a hoy.
- Nota permanente de que el último día está en curso y sus datos son parciales.

### `Math::growth_percent()` devuelve null sin base de comparación

Antes devolvía `100` fijo cuando el período previo estaba en cero, así que 0→1 y
0→5000 mostraban lo mismo. Con rangos cortos el período previo vacío es frecuente.

Ahora devuelve `null`, y los 4 sitios que lo renderizan lo tratan como "sin base"
(guion con `title` explicativo) en lugar de convertirlo a `+0%`. Son dos casos
distintos que antes se confundían: `period=all` no tiene ventana previa (no se
muestra nada), y una ventana previa vacía sí existe (se muestra `prev_label` y el
valor va como guion).

### Métricas que no responden al filtro de fechas

Quedan etiquetadas en la interfaz, sin cambiar su semántica:

- **Retención**: el filtro define quiénes forman la cohorte, no la ventana de
  actividad. Las ventanas son fijas (7 a 365 días) y se miden desde hoy, con
  techo de 365. Las tasas de dos períodos distintos no son comparables.
- **DAU/MAU**: ignora el período por completo.

Medido: la retención a 30 días da 85% con `period=7` y 5% con `period=all`, y
como pesa 0,30 en el índice de salud, ese índice varía entre 35 y 98 según el
período elegido sin que cambie nada del negocio.

### Limitación conocida: carrera de medianoche

Los presets relativos (`this_month`, `this_quarter`, `this_year`, `all`) se
resuelven contra "hoy". Un export disparado después de que la pantalla se
renderizó, cruzando la medianoche, resuelve una ventana distinta a la mostrada.

No se corrige. Está mitigado: el rango efectivo va en el nombre del archivo y en
la fila `Rango` de la hoja Resumen, así que el archivo siempre dice exactamente
qué contiene.

### Interno

- `DatePeriod::presets()` es la fuente única del selector; antes las opciones
  estaban escritas a mano en HTML en las tres vistas.
- `DatePeriod` deja de leer `$_GET`. `Page::read_period()` es el único lector del
  request. Auditados los 7 call sites de `resolve()` antes del cambio.
- `'custom'` es un valor válido del `<option>` y **no** de `DatePeriod`:
  `read_period()` lo intercepta y lo traduce. Agregarlo al allowlist volvería
  representable el estado "custom sin fechas".

## 1.2.9.3-b2

### Corregido: activity_rate ("Tasa de actividad")

El KPI mezclaba dos poblaciones distintas y superaba el 100% de forma rutinaria.

- **Numerador anterior:** `students_with_enrollments` — cualquier usuario con una
  inscripción en la ventana, incluidos los registrados años atrás.
- **Numerador nuevo:** la intersección real — usuarios que se registraron **y** se
  inscribieron, ambos dentro de la misma ventana.
- **Denominador:** sin cambios (`current_new_users`).

Por construcción el numerador ahora es un subconjunto del denominador, así que la
tasa queda acotada a 0–100.

Esto **no redefine el KPI**: la descripción que la pantalla ya mostraba
(`admin/views/dashboard.php:325`, "porcentaje de nuevos registros que se
inscribieron a al menos un curso durante el período") siempre describió este
cálculo. El código ahora la cumple.

Medido en producción, modo legacy:

| Período   | Antes  | Después |
|-----------|--------|---------|
| 7 días    | 127,1% | 81,3%   |
| 30 días   | 171,0% | 77,0%   |
| 90 días   | 126,3% | 75,3%   |
| 365 días  | 47,2%  | 44,0%   |
| Histórico | 60,2%  | 59,5%   |

**Impacto en el índice de salud.** `activity_rate` pesa 0,30 en la fórmula, así
que el indicador baja. En `period=30` pasa de 98 a **70**, que cae justo en el
umbral de "Bueno" (`>= 70`, `admin/views/dashboard.php:106`). Los umbrales no se
tocaron: el valor anterior estaba inflado por un numerador incorrecto y, además,
el clamp a 100 venía tapando el exceso.

**`active_users` no cambia** y sigue siendo un KPI legítimo por sí mismo. A
partir de esta versión **`activity_rate` ya no se puede derivar de los dos KPIs
visibles en pantalla**: dividir "Usuarios activos" por "Nuevos registros" a mano
no reproduce la tasa.

La query vive en `UsersRepository::count_registered_and_enrolled_between()`, junto
al `count_registered_between()` que produce el denominador.

## 1.2.9.2-b1

### Eliminado: quizzes de retroalimentación

La regla de completación pasa a ser: **un curso al 100% de progreso cuenta como
completado, punto.**

`TutorLms::is_effectively_completed()` queda con dos condiciones (marca formal de
Tutor LMS, o 100% de progreso). Se eliminaron la pantalla de Configuración de
quizzes, los cuatro métodos de `Settings` que la sostenían y las hasta 4
consultas SQL por par curso-usuario que ejecutaba el filtro.

**Justificación — medición sobre datos reales de producción.** El filtro era
tautológico: en 3.071 pares curso-usuario y 4 años de historia no rechazó una
sola inscripción.

| Período   | Con filtro | Sin filtro | Diferencia |
|-----------|-----------|------------|------------|
| 30 días   | 123       | 123        | **0**      |
| 365 días  | 628       | 628        | **0**      |
| Histórico | 1.178     | 1.178      | **0**      |

El promedio de 3,55 queries por par (sobre un máximo de 4) mostró que los pares
llegaban hasta la consulta de intentos y la encontraban satisfecha: los quizzes
son parte del contenido del curso, así que el 100% de progreso ya los implicaba.

Esta medición **no se puede repetir**: una vez aplicada la regla nueva, cualquier
comparación es la regla nueva contra sí misma y da cero por construcción.

**Sin impacto en los KPIs.** Valores verificados como idénticos antes y después,
en modo `legacy`:

| Período   | current_completed | completion_rate | dropout_rate |
|-----------|------------------|-----------------|--------------|
| 30 días   | 123              | 58,3            | 41,7         |
| 365 días  | 628              | 46,7            | 53,3         |
| Histórico | 1.178            | 38,2            | 61,8         |

Lo único que baja es el conteo de queries: ~4 menos en 30 días, ~284 en 365,
~366 en histórico.

**Opción huérfana a propósito.** `e3a_feedback_quiz_ids` **no se borra** de
`wp_options`: se deja para poder revertir el código sin perder la configuración
previa. Se limpia en el `uninstall.php` pendiente.

> **Advertencia para quien revierta:** restaurar el código anterior hace que esa
> opción vuelva a tener efecto de inmediato, y las cifras de completación pueden
> cambiar sin aviso.

### Otros

- La página de Configuración queda solo con el bloque temporal "Avanzado" de B1.
  Ese bloque, la página, el submenú y sus dos handlers se eliminan juntos al
  cerrar B2 (ver los `@deprecated` en `includes/Admin/Page.php`).
- Clases CSS `.e3-settings-quiz-label` / `.e3-settings-quiz-name` renombradas a
  `.e3-settings-check-label` / `.e3-settings-check-name`: las usa el toggle de
  diagnóstico de B1 y el nombre anterior ya no describía nada.
- El modo de diagnóstico `compare` deja de calcular la comparación de reglas de
  completación, que a partir de ahora daría cero siempre.
