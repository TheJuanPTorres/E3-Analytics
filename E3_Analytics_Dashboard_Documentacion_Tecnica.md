# E3 Analytics Dashboard
## Documentación Técnica — Entrega al Cliente

**Versión del plugin:** 1.2.8.5
**Plataforma:** WordPress + Tutor LMS
**Autor:** Juan Pablo Torres
**Fecha del documento:** Abril 2026

---

## Tabla de contenidos

1. [Resumen ejecutivo](#1-resumen-ejecutivo)
2. [Descripción general del sistema](#2-descripción-general-del-sistema)
3. [Módulos del dashboard](#3-módulos-del-dashboard)
   - 3.1 [Panel principal (Dashboard)](#31-panel-principal-dashboard)
   - 3.2 [Avance en abandono](#32-avance-en-abandono)
   - 3.3 [Análisis por país](#33-análisis-por-país)
   - 3.4 [Configuración](#34-configuración)
4. [Definición y fórmulas de KPIs](#4-definición-y-fórmulas-de-kpis)
   - 4.1 [KPIs del Dashboard principal](#41-kpis-del-dashboard-principal) (KPI-01 al KPI-11)
   - 4.2 [Indicador de Salud LMS](#42-indicador-de-salud-lms)
   - 4.3 [KPIs de Retención por cohorte](#43-kpis-de-retención-por-cohorte)
   - 4.4 [KPIs DAU / MAU](#44-kpis-dau--mau)
   - 4.5 [Métricas por curso (tabla de detalle)](#45-métricas-por-curso-tabla-de-detalle)
   - 4.6 [KPIs del módulo Avance en abandono](#46-kpis-del-módulo-avance-en-abandono) (KPI-A01 al KPI-A04)
   - 4.7 [KPIs del módulo Análisis por país](#47-kpis-del-módulo-análisis-por-país) (KPI-P01 al KPI-P11)
5. [Sistema de períodos y comparativas](#5-sistema-de-períodos-y-comparativas)
6. [Fuentes de datos](#6-fuentes-de-datos)
7. [Detección robusta de completación de cursos](#7-detección-robusta-de-completación-de-cursos)
8. [Exportaciones](#8-exportaciones)
9. [Arquitectura técnica](#9-arquitectura-técnica)
10. [Seguridad](#10-seguridad)
11. [Instalación y requisitos](#11-instalación-y-requisitos)
12. [Glosario de métricas](#12-glosario-de-métricas)

---

## 1. Resumen ejecutivo

**E3 Analytics Dashboard** es un plugin WordPress desarrollado a medida para plataformas de e-learning basadas en **Tutor LMS**. Proporciona un panel de indicadores clave de rendimiento (KPIs) que permite al equipo académico y directivo entender, en tiempo real y para cualquier período de tiempo, el comportamiento de los estudiantes: desde su registro y primera matrícula hasta su tasa de completación, abandono, retención y distribución geográfica.

El plugin se integra directamente con la base de datos de WordPress y Tutor LMS sin necesidad de herramientas externas, exportando los datos a formatos Excel (.xlsx) y CSV para análisis adicional.

---

## 2. Descripción general del sistema

El sistema está compuesto por cuatro páginas de administración accesibles desde el menú **E3 Analytics** en el panel de WordPress, todas protegidas por el permiso `manage_options` (rol Administrador).

| Módulo | Descripción |
|--------|-------------|
| **Dashboard** | Métricas generales del período: registros, inscripciones, finalización, retención y actividad |
| **Avance en abandono** | Estudiantes que no completaron sus cursos, clasificados por rango de progreso |
| **Análisis por país** | Distribución geográfica de registros, inscripciones y completaciones |
| **Configuración** | Gestión de quizzes de retroalimentación (excluidos del bloqueo de completación) |

Cada módulo dispone de un **selector de período** (7, 30, 90 o 365 días, o histórico completo) y la mayoría permite exportar los datos directamente desde la interfaz.

---

## 3. Módulos del dashboard

### 3.1 Panel principal (Dashboard)

Es la página de entrada del plugin. Muestra un resumen integral del rendimiento de la plataforma para el período seleccionado.

#### Componentes de la página

**Indicador de Salud LMS**
Marcador visual circular (0–100) calculado como:

```
Salud LMS = (Tasa de actividad × 0.30) + (Tasa de finalización × 0.40) + (Retención 30 días × 0.30)
```

| Rango | Estado |
|-------|--------|
| ≥ 70 | Bueno |
| 40–69 | Regular |
| < 40 | Crítico |

**Alerta de abandono:** Si la tasa de abandono supera el 60%, el sistema muestra automáticamente un aviso destacado con enlace al módulo de abandono.

**KPIs principales** (ver definiciones en sección 4):
- Nuevos registros (con variación % vs período anterior)
- Nuevos inscritos en cursos (con variación %)
- Total de inscripciones (con variación %)
- Usuarios activos
- Inscritos a otro curso (usuarios recurrentes)
- Rendimiento (cursos completados por usuario activo)
- Tasa de actividad

**Gráfico: Nuevos vs Recurrentes por curso**
Gráfico de barras con los 5 cursos con más inscripciones en el período, diferenciando entre estudiantes que se inscriben a ese curso por primera vez y estudiantes que ya lo tenían previamente.

**Panel de Insights**
Lectura rápida que consolida: tasa de finalización, tasa de abandono y ratio DAU/MAU en un solo bloque visual con barra de progreso.

**Retención por cohortes (7 días → Histórico)**
Gráfico de barras de retención por ventanas temporales: 7, 14, 30, 60, 90, 180, 365 días e histórico completo. La base de usuarios corresponde al cohorte registrado en el período seleccionado.

**Top 5 cursos**
Lista de los 5 cursos con más inscripciones en el período, incluyendo su tasa de finalización individual.

**Tabla de detalle por curso (Top 20)**
Tabla completa con hasta 20 cursos, mostrando por cada uno:
- Inscripciones del período
- Nuevos (primera inscripción al curso) vs Recurrentes
- Inscripciones del período anterior y variación porcentual
- Completados y tasa de finalización

---

### 3.2 Avance en abandono

Identifica los estudiantes que **no han completado** sus cursos y analiza en qué punto del contenido se encuentran, organizándolos por rangos de porcentaje de progreso.

#### KPIs del módulo

| KPI | Descripción |
|-----|-------------|
| Usuarios en abandono | Pares usuario/curso únicos sin completación en el período |
| Promedio de avance | Media del porcentaje de progreso de los usuarios en abandono |
| Cursos con abandono | Cantidad de cursos que tienen al menos un usuario no completado |

#### Rangos de progreso (buckets)

| Rango | Significado |
|-------|-------------|
| 0–10% | Estudiante prácticamente inactivo |
| 11–25% | Inicio del curso sin continuidad |
| 26–50% | Abandono a mitad del primer tramo |
| 51–75% | Abandono avanzado |
| 76–99% | Abandono casi al final del curso |

#### Gráfico de distribución
Gráfico de barras apiladas con los 10 cursos con mayor número de abandonos, mostrando la distribución de usuarios por rango en cada curso.

#### Tabla de detalle por curso
Para cada curso: número de usuarios en abandono, promedio de avance y desglose por cada rango (cantidad y porcentaje relativo dentro del curso).

#### Listado de usuarios
Permite seleccionar un curso específico y (opcionalmente) filtrar por rango y por búsqueda de texto (nombre, email o usuario). El listado muestra: nombre, email, porcentaje de progreso, rango y fecha de inscripción. Disponible para **exportar como CSV**.

---

### 3.3 Análisis por país

Proporciona una vista geográfica del comportamiento de la plataforma: de qué países provienen los estudiantes y cómo se distribuyen las inscripciones y completaciones.

#### KPIs del módulo

| KPI | Descripción |
|-----|-------------|
| Usuarios (universo) | Total de usuarios detectados en el período (registrados y/o con actividad) |
| Con país | Usuarios cuyo país pudo ser resuelto |
| Sin país | Usuarios sin información geográfica (agrupados como "Desconocido") |
| Cobertura país | Porcentaje del universo con país resuelto |

#### Tabla de distribución por país

Cada fila representa un país e incluye:

| Columna | Definición |
|---------|------------|
| Registros | Usuarios creados en WordPress en el período |
| Nuevos inscritos | Usuarios cuya primera matrícula histórica ocurre en el período |
| Activos | Usuarios únicos con al menos una inscripción en el período |
| Inscripciones | Total de matrículas creadas en el período |
| Completados | Inscripciones marcadas como completadas |
| % Compleción | `Completados / Inscripciones` por país |
| Recurrentes | Usuarios que se inscriben a un curso nuevo para ellos durante el período |

#### Gráfico Top 10
Gráfico de barras comparando inscripciones vs completados para los 10 países con mayor actividad.

#### Resolución del país
El sistema determina el país de cada usuario en dos pasos:
1. **Fuente primaria:** campo `country_lms` en los metadatos del usuario en WordPress.
2. **Fallback:** país del último login registrado por Tutor LMS en el meta `tutor_login_*` (JSON con campo `country`, código ISO 2).

Si ninguna fuente tiene información, el usuario se clasifica como **Desconocido**.

#### Caché
Los resultados del análisis por país se almacenan en caché durante **15 minutos** (WordPress transients) para reducir la carga sobre la base de datos en esta consulta de alto costo computacional.

#### Exportación de usuarios por país
Disponible mediante el botón **"Descargar usuarios (Excel)"**. Genera un archivo `.xlsx` con dos hojas:
- **Resumen:** metadatos del período, conteo de usuarios y cursos.
- **Usuarios:** 26 columnas de perfil fijo + una columna dinámica por cada curso publicado (valor = porcentaje de progreso del usuario, vacío si no está inscrito).

---

### 3.4 Configuración

Permite al administrador marcar quizzes específicos como **"de retroalimentación"** (no calificables). Estos quizzes son excluidos del criterio de bloqueo de completación de cursos.

Esto resuelve una limitación nativa de Tutor LMS que marca un curso como incompleto cuando existen respuestas abiertas de quizzes pendientes de revisión manual, incluso si el estudiante ha avanzado el 100% del contenido.

Los cambios se guardan en la opción de WordPress `e3a_feedback_quiz_ids` (array de IDs de quizzes).

---

## 4. Definición y fórmulas de KPIs

Esta sección documenta **todos los indicadores** del plugin con su fórmula exacta, fuente de datos, condiciones de cálculo e interpretación práctica.

---

### 4.1 KPIs del Dashboard principal

---

#### KPI-01 · Nuevos registros

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios que crearon una cuenta en la plataforma durante el período |
| **Fuente** | `wp_users.user_registered` |
| **Fórmula** | `COUNT(*) WHERE user_registered BETWEEN inicio AND fin` |
| **Unidad** | Número entero de usuarios |
| **Comparativa** | Sí — se muestra la variación % respecto al período anterior |
| **Exportable** | Sí (Excel con perfil completo del usuario) |

**Interpretación:** Es el indicador de captación más básico. Un crecimiento sostenido indica que los canales de adquisición están funcionando. Una caída puede señalar problemas de visibilidad o saturación del mercado objetivo.

> **Condición de negocio:** Se toma la fecha de creación de la cuenta en WordPress (`user_registered`), independientemente de si el usuario completó su perfil o se inscribió a algún curso.

---

#### KPI-02 · Nuevos inscritos en cursos

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios cuya **primera matrícula histórica en cualquier curso** ocurre dentro del período |
| **Fuente** | `wp_posts (tutor_enrolled)` |
| **Fórmula** | `COUNT(DISTINCT user_id) WHERE MIN(post_date de todas sus inscripciones) BETWEEN inicio AND fin` |
| **Unidad** | Número entero de usuarios |
| **Comparativa** | Sí — variación % respecto al período anterior |
| **Exportable** | Sí (Excel con perfil + curso de primera inscripción) |

**Interpretación:** Mide la conversión de cuentas creadas a estudiantes activos. Diferencia a los usuarios que se inscribieron por primera vez en su vida en esta plataforma, de los que son recurrentes. Una brecha grande entre Nuevos registros y Nuevos inscritos indica baja conversión registro → matrícula.

> **Condición de negocio:** Se considera la primera inscripción global del usuario (a cualquier curso), no la primera inscripción dentro del período. Un usuario registrado hace 6 meses que se matricula por primera vez durante el período actual **sí cuenta** como nuevo inscrito.

---

#### KPI-03 · Inscripciones totales

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Total de matrículas (registros `tutor_enrolled`) creadas en el período |
| **Fuente** | `wp_posts` — `post_type = 'tutor_enrolled'`, `post_status IN ('publish','completed')`, `post_parent > 0` |
| **Fórmula** | `COUNT(*) WHERE post_date BETWEEN inicio AND fin` |
| **Unidad** | Número entero de inscripciones (no de usuarios) |
| **Comparativa** | Sí — variación % respecto al período anterior |
| **Exportable** | Sí (Excel con inscripción, curso, usuario, progreso y estado de completación) |

**Interpretación:** Un mismo usuario puede generar múltiples inscripciones si está matriculado en varios cursos. Esta métrica refleja el volumen total de actividad de aprendizaje, no el de usuarios únicos. Útil para medir la carga de la plataforma y el interés en el catálogo de cursos.

> **Nota técnica:** Tutor LMS almacena cada inscripción como un post de tipo `tutor_enrolled` donde `post_parent` = ID del curso y `post_author` = ID del usuario. El estado `publish` equivale a "activo" y `completed` indica que Tutor marcó la inscripción como completada.

---

#### KPI-04 · Usuarios activos

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios únicos que tienen al menos una inscripción creada en el período |
| **Fuente** | `wp_posts (tutor_enrolled)` |
| **Fórmula** | `COUNT(DISTINCT post_author) WHERE post_date BETWEEN inicio AND fin` |
| **Unidad** | Número entero de usuarios |
| **Comparativa** | No (no muestra variación %) |
| **Exportable** | Sí (Excel con perfil del usuario) |

**Interpretación:** Indica cuántos estudiantes distintos generaron actividad de matrícula en el período. No mide logins ni navegación, sino actos concretos de inscripción. Es la base para calcular la Tasa de actividad y el Rendimiento.

> **Distinción importante:** Este KPI cuenta usuarios que **se inscribieron** en el período (acción de matrícula), no usuarios que simplemente iniciaron sesión o navegaron la plataforma.

---

#### KPI-05 · Inscritos a otro curso (usuarios recurrentes)

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios que ya tenían historial previo de inscripción y, durante el período, se matricularon en un curso que no tenían antes |
| **Fuente** | `wp_posts (tutor_enrolled)` — dos subconsultas correlacionadas |
| **Fórmula (período acotado)** | `COUNT(DISTINCT user_id)` donde el usuario: (1) tiene al menos 1 inscripción anterior a `inicio`, Y (2) durante el período se inscribió a un `course_id` al que no estaba matriculado antes de `inicio` |
| **Fórmula (histórico)** | `COUNT(DISTINCT user_id)` con 2 o más `course_id` distintos en toda su historia |
| **Unidad** | Número entero de usuarios |
| **Comparativa** | No |
| **Exportable** | Sí (Excel con perfil del usuario) |

**Interpretación:** Mide la "fidelización de catálogo": estudiantes que ya conocen la plataforma y eligen seguir consumiendo más cursos. Un número alto indica que el catálogo es atractivo y que los estudiantes encuentran valor continuo en la oferta.

> **Diferencia con "Usuarios activos":** Un usuario activo puede ser nuevo en la plataforma. Un "inscrito a otro curso" es necesariamente recurrente — ya tenía historia antes del período.

---

#### KPI-06 · Completados del período

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Número de inscripciones del período para las cuales el estudiante completó el curso |
| **Fuente** | `wp_posts (tutor_enrolled)` + lógica `is_effectively_completed()` |
| **Fórmula** | Para cada par `(course_id, user_id)` del período: suma de los casos donde `is_effectively_completed(course_id, user_id) = true` |
| **Unidad** | Número entero de inscripciones completadas |
| **Comparativa** | No de forma directa (aparece como parte de la Tasa de finalización) |
| **Exportable** | Sí (incluido en el export del detalle de cursos) |

**Interpretación:** Completados absolutos. Se usa para calcular la tasa de finalización y como componente del Rendimiento. Ver sección 7 para el detalle del algoritmo de completación robusta.

---

#### KPI-07 · Tasa de finalización

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Porcentaje de inscripciones del período que resultaron en completación del curso |
| **Fórmula** | `(Completados del período / Inscripciones totales del período) × 100` |
| **Unidad** | Porcentaje con 1 decimal (ej. `34.5%`) |
| **Rango válido** | 0% – 100% |
| **Umbral de alerta** | Si es < 40%, el Indicador de Salud LMS cae a zona "Regular" o "Crítico" |

**Ejemplo:**
- 120 inscripciones en el período
- 42 completados
- Tasa de finalización = `(42 / 120) × 100 = 35.0%`

**Interpretación:** Es el KPI de calidad académica más directo. Una tasa baja puede indicar: contenido demasiado extenso, dificultad mal calibrada, falta de motivación o problemas técnicos. Se complementa con el análisis de abandono para identificar en qué punto del curso se abandonan los estudiantes.

---

#### KPI-08 · Tasa de abandono

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Porcentaje estimado de inscripciones que no resultaron en completación |
| **Fórmula** | `max(0, 100 − Tasa de finalización)` |
| **Unidad** | Porcentaje con 1 decimal |
| **Umbral de alerta** | Si supera el 60%, se activa un aviso destacado en la interfaz |

**Interpretación:** Es el complemento directo de la tasa de finalización. "Estimado" porque no distingue entre inscripciones activas en curso y inscripciones genuinamente abandonadas — ambas suman al abandono mientras no haya completación.

> **Para análisis granular del abandono** (en qué punto del contenido se encuentran los estudiantes que no completaron), usar el módulo **Avance en abandono**.

---

#### KPI-09 · Tasa de actividad

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Qué proporción de los nuevos registros del período se convirtieron en estudiantes activos (al menos 1 inscripción) |
| **Fórmula** | `(Usuarios activos / Nuevos registros) × 100` |
| **Unidad** | Porcentaje con 1 decimal |
| **Condición** | Si `Nuevos registros = 0`, el resultado es `0%` |

**Ejemplo:**
- 80 nuevos registros
- 52 usuarios con al menos 1 inscripción en el período
- Tasa de actividad = `(52 / 80) × 100 = 65.0%`

**Interpretación:** Mide la conversión entre "crear una cuenta" e "inscribirse a un curso". Una tasa baja (< 40%) puede indicar: proceso de onboarding deficiente, falta de cursos atractivos, o registros de usuarios que no son el público objetivo.

> **Limitación conocida:** El denominador son los nuevos registros del período, pero los usuarios activos pueden incluir registros de períodos anteriores que se inscriben ahora. Esto puede resultar en tasas superiores al 100% en períodos con muchos usuarios recurrentes. En ese escenario el indicador debe interpretarse como "alta retención de base histórica".

---

#### KPI-10 · Rendimiento

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Promedio de cursos completados por usuario activo en el período |
| **Fórmula** | `Completados del período / Usuarios activos` |
| **Unidad** | Número decimal con 2 decimales (ej. `1.35`) |
| **Condición** | Si `Usuarios activos = 0`, el resultado es `0` |

**Ejemplo:**
- 52 usuarios activos
- 68 completados
- Rendimiento = `68 / 52 = 1.31`

**Interpretación:** Un valor de 1.0 significa que, en promedio, cada usuario activo completó exactamente 1 curso. Valores superiores a 1 indican que hay usuarios completando múltiples cursos (alta eficiencia académica). Valores muy bajos (< 0.5) indican que pocos usuarios activos están llegando a completar aunque estén inscritos.

---

#### KPI-11 · Variación porcentual (Δ%)

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Cambio relativo de un KPI respecto al período inmediatamente anterior de la misma duración |
| **Fórmula general** | `((Valor actual − Valor anterior) / Valor anterior) × 100` |
| **Caso especial** | Si el valor anterior es `0` y el actual es `> 0`, la variación se reporta como `+100%` |
| **Caso especial** | Si ambos valores son `0`, la variación es `0%` |
| **Redondeo** | 1 decimal |

**Visualización en pantalla:**
- Variación positiva → indicador verde con prefijo `+`
- Variación negativa → indicador rojo
- Sin comparación disponible (período "Histórico") → `—`

**Aplica a:** Nuevos registros, Nuevos inscritos en cursos, Inscripciones totales, y a cada curso individual en la tabla de detalle.

---

### 4.2 Indicador de Salud LMS

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Estado general de la plataforma en una escala de 0 a 100 |
| **Fórmula** | `(Tasa de actividad × 0.30) + (Tasa de finalización × 0.40) + (Retención 30 días × 0.30)` |
| **Rango** | 0 – 100 (entero) |
| **Redondeo** | `round()` al entero más cercano, luego `max(0, min(100, valor))` |

| Rango | Estado | Interpretación |
|-------|--------|----------------|
| ≥ 70 | **Bueno** | La plataforma está captando, activando y reteniendo estudiantes de forma saludable |
| 40–69 | **Regular** | Existen áreas de mejora — revisar cuál de los tres componentes arrastra el puntaje |
| < 40 | **Crítico** | Alguno o varios de los componentes está en zona de riesgo — acción inmediata recomendada |

**Ponderación y justificación:**

| Componente | Peso | Justificación |
|------------|------|---------------|
| Tasa de finalización | 40% | Es el indicador más directo de calidad académica y valor entregado |
| Tasa de actividad | 30% | Mide la conversión de registros a estudiantes activos (captación) |
| Retención 30 días | 30% | Mide si los estudiantes del cohorte siguen activos un mes después (permanencia) |

---

### 4.3 KPIs de Retención por cohorte

El sistema calcula la retención del cohorte de usuarios registrados en el período seleccionado. Para cada ventana temporal, cuenta cuántos de esos usuarios han tenido actividad de inscripción **dentro de los últimos N días** contados desde ahora.

| Campo | Detalle |
|-------|---------|
| **Base del cohorte (períodos acotados)** | `COUNT(wp_users WHERE user_registered BETWEEN inicio AND fin)` |
| **Base del cohorte (Histórico)** | `COUNT(wp_users)` — todos los usuarios de la plataforma |
| **Actividad considerada** | Tener al menos 1 inscripción `tutor_enrolled` con `post_date >= (hoy − N días)` |
| **Fórmula de tasa** | `(Usuarios activos en ventana N / Base del cohorte) × 100` |

**Ventanas disponibles y su significado:**

| Ventana | Clave | Qué responde |
|---------|-------|-------------|
| 7 días | `7` | ¿Qué % del cohorte sigue activo esta semana? |
| 14 días | `14` | ¿Sigue activo en las últimas dos semanas? |
| 30 días | `30` | ¿Actividad en el último mes? (usado también en Salud LMS) |
| 60 días | `60` | ¿Sigue activo pasado el primer mes? |
| 90 días | `90` | ¿Actividad dentro del trimestre? |
| 180 días | `180` | ¿Actividad en el último semestre? |
| 365 días | `365` | ¿Actividad en el último año? |
| Histórico | `all` | ¿Alguna vez tuvo actividad en la plataforma? |

**Ejemplo de lectura:**
> Con un período de "Últimos 30 días" y 200 usuarios registrados en ese rango:
> - Retención 7 días: 45 usuarios → **22.5%**
> - Retención 30 días: 80 usuarios → **40.0%**
> - Retención Histórico: 190 usuarios → **95.0%**

> **Nota metodológica:** Las ventanas de retención no son cohortes secuenciales (no miden "al cabo de 7 días cuántos siguen"), sino ventanas retrospectivas desde el momento actual. Esto significa que la retención de 365 días siempre será mayor o igual que la de 180 días, que a su vez será mayor o igual que la de 90 días.

---

### 4.4 KPIs DAU / MAU

Estos indicadores miden el nivel de engagement de la plataforma con una perspectiva de actividad reciente. Se calculan **siempre sobre los últimos días desde el momento actual**, independientemente del período seleccionado en el filtro.

---

#### DAU — Daily Active Users

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios únicos con al menos una inscripción en las últimas 24 horas |
| **Fuente** | `wp_posts (tutor_enrolled)` |
| **Fórmula** | `COUNT(DISTINCT post_author) WHERE post_date >= (ahora − 1 día) AND post_date <= ahora` |
| **Ventana** | Fija: últimas 24 horas desde el momento de carga de la página |
| **Unidad** | Número entero de usuarios |

> **Actividad medida:** inscripción a un curso, no navegación ni login. Un usuario que ingresa a la plataforma sin inscribirse a nada **no** cuenta como DAU.

---

#### MAU — Monthly Active Users

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios únicos con al menos una inscripción en los últimos 30 días |
| **Fuente** | `wp_posts (tutor_enrolled)` |
| **Fórmula** | `COUNT(DISTINCT post_author) WHERE post_date >= (ahora − 30 días) AND post_date <= ahora` |
| **Ventana** | Fija: últimos 30 días desde el momento de carga de la página |
| **Unidad** | Número entero de usuarios |

---

#### Ratio DAU/MAU

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Qué fracción de la base mensual activa también estuvo activa hoy |
| **Fórmula** | `(DAU / MAU) × 100` |
| **Condición** | Si `MAU = 0`, el ratio es `0%` |
| **Unidad** | Porcentaje con 1 decimal |

| Rango del ratio | Interpretación |
|-----------------|---------------|
| > 20% | Engagement alto — parte significativa de la base mensual activa a diario |
| 10–20% | Engagement moderado — actividad distribuida a lo largo del mes |
| < 10% | Actividad concentrada en pocos días o muy esporádica |

---

### 4.5 Métricas por curso (tabla de detalle)

Cada curso en la tabla de detalle del Dashboard expone los siguientes valores:

| Columna | Fórmula | Descripción |
|---------|---------|-------------|
| **Inscripciones del período** | `COUNT(*)` para ese `course_id` en el período | Total de matrículas al curso |
| **Nuevos (curso)** | `COUNT(*)` donde la `post_date` de esa inscripción coincide con el `MIN(post_date)` de ese usuario a ese curso | Primera vez que ese usuario se inscribe a ese curso específico en el período |
| **Recurrentes** | `Inscripciones del período − Nuevos (curso)` | Usuarios que ya habían tenido inscripción previa a ese mismo curso |
| **Período anterior** | `COUNT(*)` para ese `course_id` en el período anterior | Para calcular la variación |
| **Variación %** | `((Inscripciones período − Inscripciones período anterior) / Inscripciones período anterior) × 100` | Aplicando la fórmula KPI-11 |
| **Completados** | Suma de `is_effectively_completed(course_id, user_id)` para las inscripciones del período | Estudiantes que terminaron el curso |
| **Tasa de finalización** | `(Completados / Inscripciones del período) × 100` | Porcentaje de finalización específico del curso |

---

### 4.6 KPIs del módulo Avance en abandono

---

#### KPI-A01 · Usuarios en abandono

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Pares únicos (curso, usuario) del período donde el estudiante no completó y tiene menos del 100% de progreso |
| **Fuente** | `wp_posts (tutor_enrolled)` + `tutor_utils()->get_course_completed_percent()` |
| **Fórmula** | Para cada par `(course_id, user_id)` único en el período: `is_effectively_completed = false` Y `progress_percent < 100` |
| **Deduplicación** | Si el mismo usuario aparece inscrito dos veces al mismo curso en el período (caso infrecuente), se cuenta una sola vez |
| **Unidad** | Número entero de pares curso/usuario |

> **¿Por qué `progress < 100` y no solo `is_completed = false`?** Tutor LMS puede tener inscripciones antiguas con progreso = 100% pero que aún no fueron marcadas formalmente como completadas. El plugin descarta estos casos del módulo de abandono para no contaminar el análisis con falsos abandonos.

---

#### KPI-A02 · Promedio de avance de abandonados

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Media del porcentaje de progreso de todos los pares usuario/curso en abandono del período |
| **Fórmula** | `SUM(progress_percent de cada par en abandono) / COUNT(pares en abandono)` |
| **Redondeo** | 1 decimal |
| **Unidad** | Porcentaje (ej. `38.4%`) |
| **Condición** | Si `COUNT(pares en abandono) = 0`, el resultado es `0%` |

**Interpretación:** Un promedio alto (ej. 70%) sugiere que los estudiantes llegan lejos pero algo les impide terminar — posibles problemas con quizzes finales, contenido de cierre o motivación en la última etapa. Un promedio bajo (ej. 15%) indica abandono temprano — posibles problemas de onboarding, dificultad inicial o desajuste de expectativas.

---

#### KPI-A03 · Cursos con abandono

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Cantidad de cursos distintos que tienen al menos un usuario en estado de abandono |
| **Fórmula** | `COUNT(DISTINCT course_id)` del conjunto de pares en abandono |
| **Unidad** | Número entero de cursos |

---

#### KPI-A04 · Distribución por rangos de progreso (buckets)

Cada usuario en abandono se clasifica en uno de cinco rangos según su porcentaje de avance:

| Rango | Clave interna | Condición | Significado |
|-------|--------------|-----------|-------------|
| **0 – 10%** | `0_10` | `progress <= 10` | Estudiante que prácticamente no comenzó |
| **11 – 25%** | `11_25` | `progress > 10 AND <= 25` | Abandono en la introducción del curso |
| **26 – 50%** | `26_50` | `progress > 25 AND <= 50` | Abandono a mitad del primer tramo |
| **51 – 75%** | `51_75` | `progress > 50 AND <= 75` | Abandono avanzado |
| **76 – 99%** | `76_99` | `progress > 75` | Abandono muy cerca del final |

**Para cada curso, se calculan:**
- Conteo absoluto de usuarios en cada rango
- Porcentaje relativo: `(Usuarios en rango / Total usuarios en abandono del curso) × 100`
- Progreso promedio del curso: `SUM(progress) / COUNT(abandonados del curso)`

---

### 4.7 KPIs del módulo Análisis por país

---

#### KPI-P01 · Universo de usuarios

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Total de usuarios únicos detectados en el período, considerando múltiples fuentes de actividad |
| **Fórmula** | `COUNT(DISTINCT user_id)` de la unión de: usuarios registrados + usuarios con inscripciones + usuarios con primera inscripción + usuarios recurrentes en el período |
| **Unidad** | Número entero |

> Este número puede ser mayor que los "Nuevos registros" porque incluye usuarios registrados en períodos anteriores que tuvieron actividad en el período actual.

---

#### KPI-P02 · Usuarios con país resuelto

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios del universo para quienes se pudo determinar el país |
| **Fuente 1 (primaria)** | `wp_usermeta WHERE meta_key = 'country_lms'` |
| **Fuente 2 (fallback)** | `wp_usermeta WHERE meta_key LIKE 'tutor_login_%'` (JSON con campo `country` en código ISO 2) |
| **Fórmula** | `COUNT(user_id)` del universo donde se encontró valor en fuente 1 o fuente 2 |

---

#### KPI-P03 · Usuarios sin país (Desconocido)

| Campo | Detalle |
|-------|---------|
| **Fórmula** | `Universo total − Usuarios con país resuelto` |
| **Representación** | Agrupados en la fila "Desconocido" de la tabla |

---

#### KPI-P04 · Cobertura de país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Qué porcentaje del universo tiene país identificado |
| **Fórmula** | `(Usuarios con país / Universo total) × 100` |
| **Redondeo** | 1 decimal |
| **Unidad** | Porcentaje |

**Interpretación:** Una cobertura baja (< 60%) indica que el campo `country_lms` no está siendo llenado consistentemente durante el registro. Esto limita la utilidad del análisis geográfico y puede requerir una acción de limpieza o mejora en el formulario de perfil.

---

#### KPI-P05 · Registros por país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios creados en WordPress en el período, clasificados por su país |
| **Fórmula** | Para cada país: `COUNT(user_id WHERE user_registered BETWEEN inicio AND fin AND país = X)` |
| **Fuente del país** | Misma resolución doble (country_lms → fallback tutor_login) |

---

#### KPI-P06 · Nuevos inscritos por país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios cuya primera matrícula histórica (en cualquier curso) ocurrió en el período, clasificados por país |
| **Fórmula** | `COUNT(DISTINCT user_id)` donde `MIN(post_date de todas sus inscripciones) BETWEEN inicio AND fin`, agrupado por país |
| **Conteo** | 1 vez por usuario aunque se haya inscrito a múltiples cursos en el período |

---

#### KPI-P07 · Usuarios activos por país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios únicos con al menos 1 inscripción en el período, clasificados por país |
| **Fórmula** | `COUNT(DISTINCT user_id)` de inscripciones del período, agrupado por país |

---

#### KPI-P08 · Inscripciones por país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Total de matrículas creadas en el período, clasificadas por el país del usuario |
| **Fórmula** | `COUNT(*)` de inscripciones del período donde `post_status IN ('publish','completed','private')`, agrupado por país del `user_id` |
| **Nota** | Un mismo usuario aporta tantas inscripciones como cursos en los que se matricule |

---

#### KPI-P09 · Completados por país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Número de inscripciones del período que resultaron en completación, clasificadas por país |
| **Fórmula** | Para cada inscripción del período: si `post_status = 'completed'` → completado. Si no, verificar con `is_effectively_completed(course_id, user_id)`. Sumar los positivos, agrupado por país |
| **Optimización** | Los resultados de `is_effectively_completed` se cachean en memoria por par `(course_id|user_id)` para evitar consultas repetidas |

---

#### KPI-P10 · Tasa de compleción por país

| Campo | Detalle |
|-------|---------|
| **Fórmula** | `(Completados del país / Inscripciones del país) × 100` |
| **Redondeo** | 1 decimal |
| **Condición** | Si `Inscripciones = 0`, la tasa es `0%` |

**Interpretación:** Permite identificar disparidades geográficas en el rendimiento académico. Un país con muchas inscripciones pero baja compleción puede indicar barreras idiomáticas, diferencias en el acceso a internet o características del perfil del estudiante de esa región.

---

#### KPI-P11 · Usuarios recurrentes por país

| Campo | Detalle |
|-------|---------|
| **Qué mide** | Usuarios que ya estaban inscritos antes del período y, durante el período, se inscribieron a un curso nuevo para ellos, clasificados por país |
| **Fórmula (período acotado)** | `COUNT(DISTINCT user_id)` donde: (1) tiene inscripción anterior a `inicio`, Y (2) se inscribió en el período a un `course_id` que no tenía antes. Agrupado por país |
| **Fórmula (histórico)** | `COUNT(DISTINCT user_id)` con 2 o más `course_id` distintos, agrupado por país |

---

## 5. Sistema de períodos y comparativas

Todos los módulos aceptan un parámetro de período (`?period=`) con los siguientes valores:

| Valor | Rango de análisis | Período de comparación |
|-------|------------------|----------------------|
| `7` | Últimos 7 días | Los 7 días anteriores |
| `30` | Últimos 30 días | Los 30 días anteriores |
| `90` | Últimos 90 días | Los 90 días anteriores |
| `365` | Últimos 365 días | Los 365 días anteriores |
| `all` | Desde el primer registro histórico | Sin comparación |

Cuando se selecciona **"Histórico completo"**, el sistema detecta automáticamente la fecha de la primera inscripción en la base de datos y la usa como inicio del período. No se calcula variación porcentual para este caso.

Para todos los períodos acotados, la **variación porcentual** se calcula comparando el valor actual con el valor del período inmediatamente anterior de la misma duración.

---

## 6. Fuentes de datos

El plugin consulta directamente las tablas de WordPress. No requiere tablas propias adicionales.

| Tabla | Uso |
|-------|-----|
| `wp_users` | Registros de usuarios (fecha de registro) |
| `wp_usermeta` | País (`country_lms`), logins de Tutor LMS (`tutor_login_*`) |
| `wp_posts` | Inscripciones (`post_type = tutor_enrolled`, `post_parent = course_id`, `post_author = user_id`) |
| `wp_posts` (cursos/topics/quizzes) | Estructura del curso para validación de completación |
| `wp_tutor_quiz_attempts` | Intentos de quizzes para validar calificaciones pendientes |
| `wp_options` | Configuración del plugin (`e3a_feedback_quiz_ids`) |

### Estados de inscripción considerados

El plugin considera las inscripciones con los siguientes estados de `post_status`:
- `publish` — inscripción activa
- `completed` — inscripción marcada como completada por Tutor LMS
- `private` — (en algunas consultas del módulo país)

---

## 7. Detección robusta de completación de cursos

Tutor LMS tiene una limitación conocida: marca un curso como **incompleto** cuando existen quizzes de respuesta abierta cuyas respuestas están pendientes de revisión manual por el instructor, incluso si el estudiante ha navegado el 100% del contenido.

El plugin implementa el método `is_effectively_completed($course_id, $user_id)` que aplica la siguiente lógica:

```
1. ¿Tutor LMS marca el curso como completado formalmente?
   → SÍ: considerar completado.
   → NO: continuar.

2. ¿El estudiante tiene 100% de progreso?
   → NO: considerar NO completado.
   → SÍ: continuar.

3. ¿Todos los quizzes sin calificación asignada (earned_marks IS NULL)
   están marcados como "retroalimentación" en la Configuración del plugin?
   → SÍ: considerar completado.
   → NO: considerar NO completado.
```

Este método se utiliza de manera consistente en todos los módulos del plugin: dashboard, abandono y análisis por país.

**Importante:** La lista de quizzes de retroalimentación se gestiona desde la página de **Configuración** del plugin. Si no se configura ningún quiz como retroalimentación, el sistema aplica únicamente el criterio formal de Tutor LMS.

---

## 8. Exportaciones

El plugin ofrece exportaciones en dos formatos desde múltiples puntos de la interfaz.

### Exportaciones disponibles

| Módulo | Datos | Formato |
|--------|-------|---------|
| Dashboard | Nuevos registros | Excel (.xlsx) |
| Dashboard | Nuevos inscritos | Excel (.xlsx) |
| Dashboard | Inscripciones | Excel (.xlsx) |
| Dashboard | Usuarios activos | Excel (.xlsx) |
| Dashboard | Inscritos a otro curso | Excel (.xlsx) |
| Dashboard | Rendimiento | Excel (.xlsx) |
| Dashboard | Gráfico nuevos vs recurrentes | Excel (.xlsx) |
| Dashboard | Insights del período | Excel (.xlsx) |
| Dashboard | Datos de retención | Excel (.xlsx) |
| Dashboard | Top cursos | Excel (.xlsx) |
| Dashboard | Detalle completo por curso | Excel (.xlsx) |
| Abandono | Distribución por rangos | Excel (.xlsx) |
| Abandono | Detalle por curso | Excel (.xlsx) |
| Abandono | Listado de usuarios por curso | CSV (.csv, con BOM UTF-8) |
| País | Resumen por país | Excel (.xlsx) |
| País | Usuarios con perfil + progreso por curso | Excel (.xlsx, 2 hojas) |

### Estructura del export de usuarios por país

**Hoja "Resumen":**
- Período analizado (fechas de inicio y fin)
- Total de usuarios incluidos
- Total de cursos publicados

**Hoja "Usuarios":**
26 columnas de perfil fijo (ID, nombre, apellido, email, username, país, fecha de registro, etc.) + una columna por cada curso publicado con el porcentaje de progreso del usuario (celda vacía si no está inscrito).

### Seguridad en exportaciones
Todas las exportaciones verifican:
1. Que el usuario tiene el permiso `manage_options`
2. Que el nonce de WordPress es válido (protección CSRF)
3. Que los parámetros de scope y key se encuentran en listas blancas predefinidas

---

## 9. Arquitectura técnica

### Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | PHP 7.4+ (namespace `E3_Analytics`) |
| Base de datos | MySQL via `$wpdb` (WordPress) |
| Frontend | PHP templates + Vanilla JS + Chart.js 4.4.4 |
| Tipografía | Google Fonts — Inter (400/500/600/700) |
| Exportación Excel | ZipArchive (PHP nativo, sin dependencias externas) |
| Caché | WordPress Transients API (15 min, país) |

### Estructura de carpetas

```
e3-analytics-dashboard/
├── e3-analytics-dashboard.php          Punto de entrada, constantes
├── includes/
│   ├── Plugin.php                      Singleton bootstrap
│   ├── Settings.php                    Opción e3a_feedback_quiz_ids
│   ├── Admin/Page.php                  Menús, assets, enrutamiento, handlers
│   ├── Services/
│   │   ├── MetricsService.php          Lógica de KPIs del dashboard
│   │   ├── DropoutProgressService.php  Reporte de abandono y buckets
│   │   ├── CountryAnalyticsService.php Reporte por país (con caché)
│   │   ├── ExportService.php           Generación de archivos Excel/CSV
│   │   └── CountryUsersExportService.php Export de usuarios con perfil completo
│   ├── Repositories/
│   │   ├── EnrollmentsRepository.php   Consultas de inscripciones
│   │   └── UsersRepository.php         Consultas de usuarios
│   ├── Integrations/
│   │   └── TutorLms.php                Wrapper Tutor LMS
│   └── Support/
│       ├── DatePeriod.php              Resolución de períodos y rangos de fechas
│       ├── Math.php                    Cálculo de variación porcentual
│       ├── Xlsx.php                    Generador XLSX con ZipArchive
│       ├── CountryHelper.php           Normalización de países, ISO2 → nombre
│       └── BucketHelper.php            Progreso % → clave de rango
└── admin/
    ├── assets/
    │   ├── admin.css / admin.js        Estilos y lógica global
    │   ├── dropout.css / dropout.js    Estilos y lógica del módulo abandono
    │   └── country.css / country.js    Estilos y lógica del módulo país
    └── views/
        ├── dashboard.php
        ├── dropout-progress.php
        ├── country-analysis.php
        └── settings.php
```

### Flujo de datos

```
Solicitud HTTP (GET con ?page= y ?period=)
    → Admin/Page.php (enruta según slug)
        → Service (calcula KPIs y métricas)
            → Repository (consulta $wpdb)
            → TutorLms (valida completación)
        → View (template PHP)
            → wp_add_inline_script() → window.E3A_CHART (JSON)
        → Chart.js (renderiza gráficos en el navegador)
```

### Filtros y hooks disponibles

| Hook | Tipo | Propósito |
|------|------|-----------|
| `e3a_enrollment_post_type` | filter | Sobreescribir el post_type de inscripciones (por defecto: `tutor_enrolled`) |
| `e3a_export_excel` | filter | Habilitar/deshabilitar exportación Excel del dashboard principal |
| `e3a_export_country_users` | filter | Habilitar/deshabilitar exportación de usuarios por país |

### Optimizaciones de base de datos

- Todas las consultas usan `$wpdb->prepare()` con placeholders tipados (`%s`, `%d`)
- Las cláusulas `IN()` con grandes listas de IDs se procesan en **chunks de 2,000 IDs** para evitar límites de MySQL
- Los pares `(course_id, user_id)` para completación se cachean en memoria durante cada petición para evitar consultas duplicadas
- El módulo de análisis por país usa **caché de transients** de 15 minutos

---

## 10. Seguridad

### Autenticación y autorización
- Todas las páginas verifican `current_user_can('manage_options')` antes de renderizar
- Las exportaciones verifican el mismo permiso + nonce de WordPress

### Protección CSRF
- Todas las acciones POST/GET con efectos usan `wp_create_nonce()` / `wp_verify_nonce()`
- Los nonces son específicos por acción (`e3a_export_excel`, `e3a_export_dropout_users`, `e3a_export_country_users`, `e3a_save_settings`)

### Sanitización de entrada
- Todo parámetro de usuario se procesa con `sanitize_text_field()` + `wp_unslash()`
- Los IDs numéricos se castean explícitamente con `(int)`
- Los parámetros de exportación (`scope`, `key`) se validan contra listas blancas

### Consultas SQL
- 100% de las consultas usan `$wpdb->prepare()` con placeholders; no hay interpolación directa de variables en SQL
- La tabla de intentos de quizzes se verifica que exista antes de consultarla

### Salida HTML
- Todas las salidas en vistas usan `esc_html()`, `esc_attr()`, `esc_url()` según el contexto

---

## 11. Instalación y requisitos

### Requisitos del servidor

| Requisito | Versión mínima |
|-----------|---------------|
| WordPress | 5.6 o superior |
| Tutor LMS | Cualquier versión con `tutor_utils()` disponible |
| PHP | 7.4 o superior (recomendado 8.0+) |
| MySQL | 5.7 o superior |
| Extensión PHP | `ZipArchive` (para exportación Excel) |
| Extensión PHP | `mbstring` (recomendado, hay fallback sin ella) |

### Instalación

1. Copiar la carpeta `e3-analytics-dashboard/` dentro de `wp-content/plugins/`
2. Activar el plugin desde el panel de WordPress (Plugins → Activar)
3. Acceder a **E3 Analytics** en el menú lateral del administrador

No requiere:
- Composer
- npm / Node.js
- Ningún proceso de build
- Creación manual de tablas en la base de datos

### Primer uso recomendado

1. Ingresar a **E3 Analytics → Configuración** y revisar los quizzes de la plataforma
2. Marcar como "retroalimentación" aquellos quizzes que no deben bloquear la completación de cursos
3. Guardar y navegar al **Dashboard** seleccionando el período deseado

---

## 12. Glosario de métricas

| ID | Término | Definición |
|----|---------|-----------|
| KPI-01 | **Nuevos registros** | Usuarios que crearon cuenta en WordPress en el período (`user_registered`) |
| KPI-02 | **Nuevos inscritos en cursos** | Usuarios cuya primera matrícula histórica (cualquier curso) ocurre en el período |
| KPI-03 | **Inscripciones totales** | Total de registros `tutor_enrolled` creados en el período; un usuario puede aportar varios |
| KPI-04 | **Usuarios activos** | Usuarios únicos con al menos 1 inscripción en el período |
| KPI-05 | **Inscritos a otro curso** | Usuarios recurrentes que se matriculan en un curso nuevo para ellos durante el período |
| KPI-06 | **Completados** | Inscripciones del período donde `is_effectively_completed = true` |
| KPI-07 | **Tasa de finalización** | `(Completados / Inscripciones) × 100` para el período |
| KPI-08 | **Tasa de abandono** | `100 − Tasa de finalización` |
| KPI-09 | **Tasa de actividad** | `(Usuarios activos / Nuevos registros) × 100` |
| KPI-10 | **Rendimiento** | `Completados / Usuarios activos` — promedio de cursos terminados por usuario |
| KPI-11 | **Variación %** | `((Actual − Anterior) / Anterior) × 100` — cambio vs período anterior igual de largo |
| — | **Salud LMS** | `(Actividad × 0.30) + (Finalización × 0.40) + (Retención 30d × 0.30)`, escala 0–100 |
| — | **DAU** | Usuarios únicos con inscripción en las últimas 24 horas |
| — | **MAU** | Usuarios únicos con inscripción en los últimos 30 días |
| — | **Ratio DAU/MAU** | `(DAU / MAU) × 100` — engagement reciente vs base mensual |
| — | **Retención (ventana N)** | `(Usuarios del cohorte activos en los últimos N días / Base del cohorte) × 100` |
| KPI-A01 | **Usuarios en abandono** | Pares (curso, usuario) del período con `is_completed = false` y `progress < 100%` |
| KPI-A02 | **Promedio de avance** | `SUM(progress%) / COUNT(pares en abandono)` |
| KPI-A03 | **Cursos con abandono** | `COUNT(DISTINCT course_id)` del conjunto en abandono |
| KPI-A04 | **Bucket de progreso** | Rango de avance: 0–10% / 11–25% / 26–50% / 51–75% / 76–99% |
| KPI-P01 | **Universo de usuarios** | Unión de IDs de usuarios registrados + con inscripción + primeros inscritos + recurrentes |
| KPI-P02 | **Usuarios con país** | Universo con `country_lms` o fallback en `tutor_login_*` |
| KPI-P03 | **Usuarios sin país** | `Universo − Usuarios con país` — agrupados como "Desconocido" |
| KPI-P04 | **Cobertura país** | `(Con país / Universo) × 100` |
| KPI-P05 | **Registros por país** | Nuevas cuentas WordPress en el período, clasificadas por país |
| KPI-P06 | **Nuevos inscritos por país** | Primera matrícula histórica en el período, clasificada por país |
| KPI-P07 | **Activos por país** | Usuarios únicos con ≥ 1 inscripción en el período, por país |
| KPI-P08 | **Inscripciones por país** | Total de matrículas del período, clasificadas por país del usuario |
| KPI-P09 | **Completados por país** | Inscripciones del período marcadas como completadas, por país |
| KPI-P10 | **% Compleción por país** | `(Completados del país / Inscripciones del país) × 100` |
| KPI-P11 | **Recurrentes por país** | Usuarios con historial previo que se inscriben a un curso nuevo en el período, por país |
| — | **Quiz de retroalimentación** | Quiz marcado en Configuración como no calificable; no bloquea la completación aunque no tenga calificación asignada |
| — | **Período anterior** | Ventana de tiempo inmediatamente anterior al período seleccionado, de igual duración |
| — | **Histórico completo** | Desde la primera inscripción registrada en la plataforma hasta el momento actual |

---

*Documento generado para el proyecto E3 Analytics Dashboard — Abril 2026.*
*Desarrollado por Juan Pablo Torres para la plataforma E3.*
