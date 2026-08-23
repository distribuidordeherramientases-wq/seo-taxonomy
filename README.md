# SEO Taxonomy

**SEO Taxonomy** es un plugin para WordPress y WooCommerce orientado a la gestión centralizada de la arquitectura SEO de un sitio web.

Permite organizar páginas estratégicas, productos, categorías, imágenes y sus relaciones, además de incorporar herramientas de análisis, automatización y mejora de contenidos asistida por IA.

---

## 🚀 Objetivo del plugin

SEO Taxonomy busca centralizar en un único entorno la gestión SEO de sitios WordPress con catálogos de productos.

El plugin permite trabajar tanto sobre los elementos individuales del sitio como sobre la relación existente entre ellos:

* Productos
* Categorías
* Páginas y entradas
* Imágenes
* Taxonomías
* Hubs y clusters
* Plantillas SEO
* Redirecciones
* Informes
* Contenido asistido por IA

---

## 🧭 Arquitectura SEO

Una de las funciones principales de SEO Taxonomy es organizar la estructura del sitio mediante relaciones entre páginas, categorías y productos.

Flujo recomendado:

```text
Página / Contenido
        │
        ▼
Asignación de plantilla
        │
        ▼
Taxonomía SEO
        │
        ▼
Hub / Cluster / Relaciones
        │
        ▼
Categorías relacionadas
        │
        ▼
Productos
        │
        ▼
Análisis SEO
        │
        ▼
Mejoras asistidas por IA
```

Publicar una página en WordPress no significa que forme automáticamente parte de la arquitectura SEO.

Su plantilla, función y relaciones deben configurarse expresamente dentro de SEO Taxonomy.

---

# 📦 Módulos principales

## Products

Gestión SEO centralizada de productos WooCommerce.

Permite trabajar desde una única interfaz con información relacionada con:

* Títulos
* Descripciones
* Contenido
* Imágenes
* Datos SEO
* Mejoras asistidas por IA

---

## Categories

Gestión y optimización de categorías y taxonomías.

Permite organizar la estructura de categorías y mejorar su función dentro de la arquitectura SEO del sitio.

---

## Pages

Administración SEO de páginas y entradas de WordPress.

Facilita la revisión y edición de información SEO desde una interfaz centralizada.

---

## Pictures

Gestión SEO de las imágenes del sitio.

Permite trabajar con elementos como:

* Títulos
* Atributos ALT
* Descripciones
* Metadatos

---

## Reports

Sistema de informes para analizar el estado SEO del proyecto.

Permite detectar problemas, revisar información y localizar posibles mejoras.

---

## Taxonomy

Gestión de la arquitectura SEO del sitio.

Permite definir relaciones entre páginas, categorías y otros elementos para construir estructuras basadas en:

* Hubs
* Clusters
* Nodos SEO
* Relaciones entre contenidos
* Categorías representativas

---

# 🛠️ Herramientas

SEO Taxonomy incluye además diferentes herramientas avanzadas.

## Templates

Gestión de plantillas SEO utilizadas por las diferentes páginas y elementos del sitio.

Las plantillas permiten establecer el rol de determinados contenidos dentro de la arquitectura.

---

## Search

Configuración y herramientas relacionadas con el buscador del sitio.

---

## Redirects

Gestión de redirecciones.

---

## Marketing

Herramientas relacionadas con acciones y procesos de marketing.

---

## Data Table

Vista de datos tipo hoja de cálculo para facilitar la revisión y edición de información.

---

## Clean DB

Herramientas de mantenimiento y limpieza de la base de datos.

---

## Import / Export

Sistema para importar y exportar información utilizada por el plugin.

---

## FAQs

Gestión de preguntas frecuentes.

---

## Estado del servidor

Panel de diagnóstico para comprobar el estado del servidor y ejecutar pruebas relacionadas con las funciones utilizadas por SEO Taxonomy.

---

## Plugin Validation

Framework interno de validación y pruebas del plugin.

Permite detectar problemas y comprobar el funcionamiento de diferentes componentes del sistema.

---

## Menu Manager

Sistema de gestión de menús y herramientas disponibles dentro de SEO Taxonomy.

---

# 📚 Procedimiento recomendado para crear contenido

Para integrar correctamente una nueva página dentro de la arquitectura SEO se recomienda seguir este proceso:

### 1. Crear y publicar la página

Añadir el título, contenido, imágenes y datos necesarios antes de incorporarla a la estructura SEO.

### 2. Asignar una plantilla

Seleccionar la plantilla adecuada dependiendo de la función de la página.

Por ejemplo:

* Cluster
* Hub primario
* Hub secundario
* Otros tipos disponibles

### 3. Asociar la página desde Taxonomy

Definir su posición dentro de la arquitectura SEO y establecer sus relaciones con otros nodos.

### 4. Seleccionar categorías representativas

Cuando sea necesario, asociar las categorías que se mostrarán con fines informativos, comerciales o de navegación.

### 5. Comprobar los productos

Verificar que las categorías relacionadas contienen productos relevantes y correctamente clasificados.

### 6. Revisar el SEO

Analizar el contenido y comprobar las recomendaciones detectadas.

### 7. Aplicar mejoras con IA

Utilizar las herramientas de inteligencia artificial como apoyo para mejorar el contenido cuando las recomendaciones sean coherentes con el objetivo de la página.

---

# 🤖 Inteligencia Artificial

SEO Taxonomy incorpora funciones de asistencia mediante IA en diferentes procesos relacionados con el contenido y la optimización SEO.

La IA se utiliza como herramienta de apoyo.

Las modificaciones propuestas deben revisarse antes de aplicarse definitivamente.

---

# 🧩 Estructura del plugin

La estructura concreta puede evolucionar durante el desarrollo, pero el repositorio contiene el código completo necesario para el funcionamiento de SEO Taxonomy.

Ejemplo:

```text
seo-taxonomy/
│
├── admin/
├── assets/
├── includes/
├── languages/
│
├── seo-taxonomy.php
├── readme.txt
├── uninstall.php
└── README.md
```

---

# 💻 Instalación

## Instalación manual

1. Descargar el plugin.
2. Descomprimir el archivo si es necesario.
3. Subir la carpeta del plugin a:

```text
/wp-content/plugins/seo-taxonomy/
```

4. Acceder al panel de administración de WordPress.
5. Ir a **Plugins**.
6. Activar **SEO Taxonomy**.

---

# 🔄 Desarrollo

El desarrollo de SEO Taxonomy se gestiona mediante GitHub.

GitHub se utiliza para:

* Control de versiones
* Desarrollo de nuevas funcionalidades
* Corrección de errores
* Gestión de Issues
* Historial de cambios
* Preparación de futuras versiones
* Releases

El repositorio debe considerarse la fuente principal del código del plugin.

---

# 🐛 Issues

Los errores, mejoras y nuevas funcionalidades se gestionan mediante **GitHub Issues**.

Cada Issue puede utilizarse para documentar:

* Bugs
* Mejoras
* Nuevas funcionalidades
* Refactorizaciones
* Problemas de compatibilidad
* Pruebas pendientes

---

# 🧪 Estado del proyecto

SEO Taxonomy se encuentra actualmente en desarrollo activo.

La arquitectura, funcionalidades y estructura interna pueden evolucionar a medida que se incorporan nuevas herramientas y se prepara el plugin para su distribución.

---

# 📦 Versiones

Las versiones estables podrán distribuirse mediante **GitHub Releases**.

Ejemplo:

```text
v0.1.0
v0.2.0
v0.5.0
v1.0.0
```

Se recomienda utilizar versionado semántico:

```text
MAJOR.MINOR.PATCH
```

Por ejemplo:

```text
1.4.2
```

* `1` → versión principal
* `4` → nuevas funcionalidades compatibles
* `2` → correcciones o pequeños cambios

---

# 🗺️ Evolución del proyecto

Entre los objetivos del proyecto se encuentran:

* Mejorar la arquitectura SEO del sitio.
* Centralizar la gestión de grandes catálogos.
* Facilitar el mantenimiento SEO de WooCommerce.
* Automatizar tareas repetitivas.
* Mejorar las herramientas de análisis.
* Ampliar los sistemas de validación interna.
* Mejorar la integración de IA.
* Preparar el plugin para su distribución.
* Crear un sistema de versiones estable.
* Preparar futuras ediciones públicas y comerciales.

---

# 🔐 Seguridad

Las credenciales, claves API, tokens y otros datos sensibles no deben almacenarse directamente en el repositorio.

Antes de publicar nuevas versiones debe comprobarse que el código no contiene información privada del servidor o de las instalaciones donde se utiliza el plugin.

---

# 📄 Licencia

Licencia pendiente de definir.

---

## SEO Taxonomy

**Arquitectura SEO, catálogo, contenidos y herramientas de optimización para WordPress y WooCommerce desde un único sistema.**
