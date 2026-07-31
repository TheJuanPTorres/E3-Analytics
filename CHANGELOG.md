# Changelog — E3 Analytics Dashboard

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
