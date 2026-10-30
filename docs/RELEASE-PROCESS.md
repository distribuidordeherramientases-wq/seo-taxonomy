# Versiones y publicación

Este documento define el flujo de trabajo para la familia TaxoCommerce. La línea monolítica TaxoCommerce SEO queda cerrada en 2.4.1; desde el milestone 2.4.2, cada plugin independiente mantiene su propia versión y su propio historial.

## 1. Principio general

El repositorio utiliza este flujo:

```text
feature/* o fix/*
        ↓
     staging
        ↓
 validación funcional
        ↓
       main
        ↓
   producción
```

- **feature/fix**: desarrollo aislado.
- **staging**: próxima versión candidata a producción.
- **main**: última versión aprobada para producción.
- **producción**: se despliega manualmente desde `main`.

Los cambios normales se acumulan y validan en staging durante la semana. La promoción a producción se realiza, como norma general, **una vez por semana**.

Los hotfix urgentes pueden seguir un ciclo más corto, pero deben pasar por staging siempre que sea posible.

---

## 2. Documentos de versión

### `CHANGELOG.md` raíz

Es el histórico técnico de la etapa monolítica y queda cerrado en TaxoCommerce SEO 2.4.1. No debe recibir cambios funcionales de los plugins independientes.

Debe registrar cambios relevantes que hayan llegado a `staging`:

- nuevas funcionalidades;
- correcciones;
- cambios de comportamiento;
- migraciones o cambios de esquema;
- mejoras de rendimiento;
- cambios de interfaz;
- deprecaciones;
- incidencias resueltas relevantes.

No debe convertirse en un listado de ramas ni commits.

La unidad de documentación es **el cambio funcional**, no el nombre de la branch.

### `readme.txt`

El `readme.txt` raíz corresponde al monolito 2.4.1. Cada plugin `taxocommerce-*` mantiene su `readme.txt` como documento público para WordPress.org; el historial **editorial maestro** de nuevas versiones se conserva en su `CHANGELOG.md` y la evolución original del monolito en su `changelog.txt`.

Contiene:

- nombre del plugin;
- requisitos;
- versión estable;
- descripción pública;
- instalación;
- preguntas frecuentes;
- capturas;
- changelog resumido;
- aviso de actualización;
- licencia.

Su sección `== Changelog ==` contiene un **resumen público de la versión vigente y las recientes**, redactado a partir del `CHANGELOG.md` del mismo plugin. WordPress.org no lee ni muestra automáticamente `CHANGELOG.md` o `changelog.txt`: al preparar una publicación se debe sincronizar expresamente el resumen público. El archivo `changelog.txt` es documentación histórica auxiliar, no una sección automática del directorio.


### `changelog.txt` y `CHANGELOG.md` de cada plugin

- `changelog.txt` registra hechos y funciones verificables del antiguo TaxoCommerce SEO hasta 2.4.1, con las versiones originales del monolito. No atribuye esas versiones al nuevo módulo.
- `CHANGELOG.md` es el registro maestro **solo de las versiones autónomas**. Se conserva completo y se actualiza al implementar y verificar cada nueva versión. No se inventan fechas ni se presentan propuestas no integradas como publicaciones.
- **Flujo de un solo archivo durante el desarrollo:** editar `CHANGELOG.md` del plugin afectado. **Paso obligatorio antes de publicar:** copiar o generar un resumen de la versión vigente en el `readme.txt` de ese plugin. Actualmente esa sincronización no está automatizada; no debe afirmarse que WordPress.org la realiza por sí mismo.
- El `changelog.txt` histórico permanece congelado salvo correcciones justificadas por nuevas evidencias.

### `README.md`

Presentación del proyecto en GitHub.

Debe explicar arquitectura, módulos, instalación, documentación y flujo general de desarrollo sin sustituir al manual de usuario ni al changelog.

### Manual y Wiki

Cuando una versión cambia una pantalla, botón, casilla, flujo o resultado visible, debe actualizarse el manual correspondiente antes de considerar terminada la funcionalidad.

Los documentos canónicos viven en `docs/` y las páginas configuradas se sincronizan con la GitHub Wiki desde `staging`.

### `LICENSE`

Define la licencia del código distribuido.

La documentación pública debe indicar la misma licencia que este archivo.

---

## 3. Trabajo diario

### Desarrollo

1. Crear una rama `feature/*` o `fix/*`.
2. Realizar el cambio.
3. Validar sintaxis y comportamiento.
4. Crear PR hacia `staging`.
5. Fusionar cuando la función esté suficientemente preparada para probarse.
6. Dejar que GitHub Actions despliegue automáticamente staging.
7. Probar en el WordPress de staging.

### Documentación

Cuando un cambio de un plugin independiente queda validado en staging:

1. documentar la interfaz si ha cambiado;
2. registrar las novedades validadas en el `CHANGELOG.md` del plugin; la edición diaria del historial se realiza **solo en ese archivo**;
3. al preparar la publicación, trasladar al `readme.txt` el resumen público de la versión actual desde `CHANGELOG.md` y comprobar su formato con el validador de WordPress.org; actualizar manuales cuando proceda;
4. no modificar `CHANGELOG.md` ni `readme.txt` raíz salvo que el cambio sea un hotfix crítico del monolito 2.4.1.

---

## 4. Versionado modular desde el milestone 2.4.2

Desde el milestone 2.4.2 no existe una versión global que deba aplicarse a todos los plugins.

Ejemplo:

```text
TaxoCommerce Core       0.4.28 -> 0.4.29
TaxoCommerce Images     0.2.8  -> 0.2.9
TaxoCommerce Auditor    0.x    -> 0.x+1
```

Sólo incrementa versión el plugin cuyo código cambia. Cada plugin registra su historial completo **a partir de su independencia** en `taxocommerce-*/CHANGELOG.md` y su etapa anterior en `taxocommerce-*/changelog.txt`. El `readme.txt` mantiene la versión vigente y el resumen visible en WordPress.org. Los milestones globales coordinan el trabajo, pero no sustituyen al versionado individual.

El `CHANGELOG.md` raíz y el `readme.txt` raíz permanecen congelados en 2.4.1 salvo correcciones críticas específicas del monolito.

---

## 4 bis. Sección Unreleased histórica

Esta mecánica se conserva únicamente como referencia de cómo se publicaba el monolito antes de 2.4.1. **No se abrirá una nueva sección `Unreleased` en el `CHANGELOG.md` raíz.**

Para los plugins independientes, el trabajo pendiente se gestiona mediante Issues, PRs y milestones. Se registran solo cambios desarrollados y verificados en el `CHANGELOG.md`; antes de publicar, se sincroniza el resumen del `readme.txt`. Una PR sin fusionar no constituye por sí misma una versión publicada.
---

## 5. Corte semanal de producción

Antes de publicar:

### Alcance

- Revisar qué cambios de staging están realmente terminados.
- No promover cambios experimentales o parcialmente probados.
- Confirmar que la documentación visible está actualizada.

### Versiones

Para plugins independientes, comprobar coherencia únicamente dentro del plugin afectado:

- cabecera `Version:` de `taxocommerce-<plugin>.php`;
- constante de versión propia, cuando exista;
- `Stable tag` de su `readme.txt`;
- entrada correspondiente en su `CHANGELOG.md` y resumen coherente en su `readme.txt` antes de publicar;

No se incrementa la versión de otros plugins que no hayan cambiado. El monolito conserva `2.4.1`, `SEO_SYSTEM_VERSION = 2.4.1` y `Stable tag: 2.4.1`.

`SEO_SYSTEM_DB_VERSION` del monolito solo debe cambiar ante una corrección crítica que requiera una migración real.

### Validaciones

Como mínimo:

- sintaxis PHP;
- carga del panel de administración;
- funcionamiento del módulo modificado;
- procesos programados afectados;
- ausencia de errores PHP visibles;
- importación/exportación cuando corresponda;
- operaciones destructivas y sus confirmaciones;
- smoke test del frontend si el cambio afecta plantillas;
- comprobación de checkout/precios si el cambio es comercial.

### Publicación

1. Comparar `staging` con `main`.
2. Crear PR `staging → main`.
3. Revisar el conjunto completo de cambios.
4. Fusionar a `main`.
5. Crear etiqueta/release de GitHub cuando corresponda.
6. Ejecutar manualmente el workflow de despliegue de `main` a producción.
7. Realizar comprobación posterior al despliegue.

---

## 6. Comprobación posterior a producción

Después de cada release:

- abrir WordPress y comprobar que el plugin está activo;
- verificar la versión instalada;
- revisar la pantalla o proceso principal cambiado;
- comprobar que no existen errores 500;
- revisar checkout/precios si procede;
- confirmar que los procesos automáticos siguen operativos;
- verificar que la documentación pública corresponde con la versión publicada.

Si aparece una incidencia crítica, preparar un hotfix pequeño y trazable.

---

## 7. Hotfix

Se considera hotfix un cambio urgente por:

- error que impide vender;
- checkout o pagos;
- precios incorrectos;
- pérdida o corrupción de datos;
- caída de una función crítica;
- problema de seguridad;
- error SEO grave que requiera corrección inmediata.

Flujo recomendado:

```text
fix/hotfix-...
      ↓
   staging
      ↓
 prueba rápida
      ↓
    main
      ↓
 producción
```

Un hotfix de un plugin independiente debe quedar registrado en su `CHANGELOG.md` y resumido en el `readme.txt` al publicarse. `CHANGELOG.md` y `readme.txt` raíz sólo se modifican si el hotfix afecta específicamente al monolito 2.4.1.

---

## 8. Qué no debe aparecer en el changelog público

Evitar detalles internos sin valor para el usuario:

- nombres de ramas;
- hashes de commits;
- credenciales;
- rutas del hosting;
- nombres internos sensibles;
- datos personales;
- pruebas descartadas;
- detalles de infraestructura que no afecten al uso.

Sí deben aparecer:

- funciones nuevas;
- cambios visibles;
- correcciones;
- mejoras de rendimiento;
- compatibilidad;
- comportamiento de actualización;
- cambios que puedan requerir revisión después de instalar.

---

## 9. Convención de versión

- **TaxoCommerce SEO monolítico:** cerrado en `2.4.1`.
- **Milestones globales (`2.4.2`, posteriores):** coordinan trabajo y no son versiones del monolito.
- **Plugins independientes:** cada uno evoluciona con su propio versionado (`0.4.29`, `0.2.9`, etc.).
- Cambiar un plugin no obliga a versionar ni publicar los demás.
- El historial maestro de las versiones independientes vive en `taxocommerce-*/CHANGELOG.md`; `changelog.txt` documenta la evolución anterior en el monolito; `readme.txt` muestra el resumen público exigido por WordPress.org.
---

## 10. Checklist resumido

Antes de producción:

- [ ] Cambios integrados en staging.
- [ ] Pruebas funcionales realizadas.
- [ ] Novedades registradas en `CHANGELOG.md` del plugin y resumen publicado en su `readme.txt`.
- [ ] Versión del plugin afectado consistente en su cabecera, constante y `Stable tag`.
- [ ] Manual/Wiki actualizado si cambió la interfaz.
- [ ] Sintaxis PHP validada.
- [ ] PR staging → main revisada.
- [ ] Despliegue manual ejecutado.
- [ ] Smoke test de producción completado.
- [ ] El monolito sigue en 2.4.1 salvo hotfix crítico explícito.

---

## 11. Fuente de verdad

Para evitar documentación divergente:

- `CHANGELOG.md` raíz = histórico congelado del monolito hasta 2.4.1.
- `readme.txt` raíz = documento público final del monolito 2.4.1.
- `taxocommerce-*/changelog.txt` = evolución previa en el monolito (histórica, no versiones autónomas).
- `taxocommerce-*/CHANGELOG.md` = historial maestro de versiones independientes; único archivo de novedades que editamos durante el desarrollo.
- `taxocommerce-*/readme.txt` = metadatos públicos y extracto de versiones que WordPress.org efectivamente presenta.
- `docs/PLUGIN-HISTORY-SOURCES.md` = índice de evidencias originales y PRs; no implica pruebas o despliegues no verificados.
- `docs/` = documentación funcional y operativa.
- GitHub Wiki = publicación navegable de la documentación canónica.
- `main` = código aprobado para producción.
- `staging` = próxima versión candidata.
