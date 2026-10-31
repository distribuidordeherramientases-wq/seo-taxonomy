# TaxoCommerce SEO

## Estado del proyecto

**TaxoCommerce SEO 2.4.1 cierra la línea monolítica histórica.**

A partir de este punto, el desarrollo funcional continúa en los plugins independientes de la familia TaxoCommerce. El plugin monolítico queda congelado para desarrollo ordinario y sólo podrá recibir correcciones críticas de compatibilidad, migración o seguridad cuando sean estrictamente necesarias.

Cada plugin independiente mantiene su versión y resumen público en `readme.txt`, su evolución histórica procedente del monolito en `changelog.txt` y el historial maestro de nuevas versiones en `CHANGELOG.md`, dentro de su propia carpeta. Los milestones globales, como `2.4.2`, coordinan trabajo entre plugins pero no son versiones del monolito ni obligan a sincronizar las versiones de toda la familia.


**TaxoCommerce SEO** es un plugin para WordPress y WooCommerce orientado a la gestión centralizada de la arquitectura SEO de un sitio web.

Permite organizar páginas estratégicas, productos, categorías, imágenes y sus relaciones, además de incorporar herramientas de análisis, automatización y mejora de contenidos asistida por IA.

---

## 🚀 Objetivo del plugin

TaxoCommerce SEO busca centralizar en un único entorno la gestión SEO de sitios WordPress con catálogos de productos.

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

Una de las funciones principales de TaxoCommerce SEO es organizar la estructura del sitio mediante relaciones entre páginas, categorías y productos.

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

Su plantilla, función y relaciones deben configurarse expresamente dentro de TaxoCommerce SEO.

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

TaxoCommerce SEO incluye además diferentes herramientas avanzadas.

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

Panel de diagnóstico para comprobar el estado del servidor y ejecutar pruebas relacionadas con las funciones utilizadas por TaxoCommerce SEO.

---

## Plugin Validation

Framework interno de validación y pruebas del plugin.

Permite detectar problemas y comprobar el funcionamiento de diferentes componentes del sistema.

---

## Menu Manager

Sistema de gestión de menús y herramientas disponibles dentro de TaxoCommerce SEO.

---

# 📖 Documentación y manual de usuario

La documentación del proyecto se divide en dos niveles:

- **Introducción y visión general**: este README explica el objetivo, la arquitectura y los módulos principales.
- **Manual de usuario**: documentación operativa de las pantallas del plugin, con pestañas, botones, filtros, casillas, estados, acciones, efectos y ejemplos.

Documentación disponible:

- [Índice de documentación](docs/README.md)
- [Manual de usuario](docs/MANUAL-USUARIO.md)
- [Marketing y campañas](docs/MARKETING.md)
- [Versiones y publicación](docs/RELEASE-PROCESS.md)
- [Conexiones y credenciales](docs/CONEXIONES-CREDENCIALES.md)
- [Historial de cambios](CHANGELOG.md)

El manual se centra en la interfaz visible para el usuario y evita entrar en clases, funciones PHP o archivos internos salvo cuando sea necesario para comprender una operación.

Los ejemplos de credenciales, tokens, secretos y contraseñas de la documentación son **ficticios** y se incluyen únicamente para mostrar el formato esperado.

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

TaxoCommerce SEO incorpora funciones de asistencia mediante IA en diferentes procesos relacionados con el contenido y la optimización SEO.

La IA se utiliza como herramienta de apoyo.

Las modificaciones propuestas deben revisarse antes de aplicarse definitivamente.

---

# 🧩 Estructura del plugin

La estructura concreta puede evolucionar durante el desarrollo, pero el repositorio contiene el código completo necesario para el funcionamiento de TaxoCommerce SEO.

Ejemplo:

```text
taxocommerce-seo/
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
/wp-content/plugins/taxocommerce-seo/
```

4. Acceder al panel de administración de WordPress.
5. Ir a **Plugins**.
6. Activar **TaxoCommerce SEO**.

---

# 🔄 Desarrollo

El desarrollo de TaxoCommerce SEO se gestiona mediante GitHub.

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

La línea monolítica TaxoCommerce SEO queda cerrada en la versión 2.4.1. El desarrollo activo continúa en los plugins independientes de la familia TaxoCommerce.

---

# 📦 Versiones

El flujo de publicación utiliza `staging` como candidata a la próxima versión y `main` como código aprobado para producción.

Los cambios normales se validan durante la semana en staging y se promueven a producción de forma agrupada, normalmente una vez por semana. Los hotfix críticos pueden seguir un ciclo más corto.

Para el monolito, la versión final queda fijada en 2.4.1. A partir de ahí, cada plugin independiente mantiene su propia versión y changelog en su carpeta.

Consulta [Versiones y publicación](docs/RELEASE-PROCESS.md) para el procedimiento completo.

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

TaxoCommerce SEO se distribuye bajo licencia **MIT**, de acuerdo con el archivo [LICENSE](LICENSE).

---

## TaxoCommerce SEO

**Arquitectura SEO, catálogo, contenidos y herramientas de optimización para WordPress y WooCommerce desde un único sistema.**
