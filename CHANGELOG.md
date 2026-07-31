# Changelog — E3 Analytics Dashboard

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
