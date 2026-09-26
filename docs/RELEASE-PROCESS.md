# Versiones y publicación

Este documento define el flujo de trabajo para preparar versiones de SEO Taxonomy, mantener el historial de cambios y publicar actualizaciones de forma controlada.

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

### `CHANGELOG.md`

Es el historial técnico principal del proyecto.

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

Es el documento público preparado para la distribución del plugin en el ecosistema WordPress.

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

Su changelog debe ser comprensible para un usuario final y puede resumir el detalle técnico de `CHANGELOG.md`.

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

Cuando el cambio queda validado en staging:

1. documentar la interfaz si ha cambiado;
2. añadir una entrada a `CHANGELOG.md`;
3. actualizar documentación específica si existe;
4. actualizar `readme.txt` cuando el cambio deba aparecer en la próxima versión pública.

---

## 4. Sección Unreleased

Mientras se acumulan cambios para la próxima publicación, `CHANGELOG.md` mantiene al principio:

```markdown
## [Unreleased]

### Marketing
- ...

### Correcciones
- ...
```

Cuando se hace el corte semanal:

1. se decide el número de versión;
2. `Unreleased` se convierte en la versión fechada;
3. se crea una nueva sección `Unreleased` vacía para el siguiente ciclo.

Esto permite que staging actúe como una verdadera candidata a la próxima release.

---

## 5. Corte semanal de producción

Antes de publicar:

### Alcance

- Revisar qué cambios de staging están realmente terminados.
- No promover cambios experimentales o parcialmente probados.
- Confirmar que la documentación visible está actualizada.

### Versiones

Comprobar coherencia entre:

- cabecera `Version:` de `seo-taxonomy.php`;
- `SEO_SYSTEM_VERSION`;
- `Stable tag` de `readme.txt`;
- encabezado de la versión en `CHANGELOG.md`;
- changelog público de `readme.txt`.

`SEO_SYSTEM_DB_VERSION` solo debe cambiar cuando exista una modificación real del esquema o una migración que lo requiera.

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

El hotfix también debe quedar registrado en `CHANGELOG.md` y en `readme.txt` si genera una nueva versión pública.

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

El proyecto utiliza actualmente versiones del tipo:

```text
2.3.7
2.3.8.1
```

Mientras se mantenga este esquema, todos los documentos de una misma publicación deben usar exactamente el mismo identificador.

No se debe actualizar `Stable tag` o la versión del plugin de forma aislada.

---

## 10. Checklist resumido

Antes de producción:

- [ ] Cambios integrados en staging.
- [ ] Pruebas funcionales realizadas.
- [ ] `CHANGELOG.md` actualizado.
- [ ] `readme.txt` actualizado.
- [ ] Manual/Wiki actualizado si cambió la interfaz.
- [ ] Versión consistente en todos los archivos.
- [ ] Sintaxis PHP validada.
- [ ] PR staging → main revisada.
- [ ] Despliegue manual ejecutado.
- [ ] Smoke test de producción completado.

---

## 11. Fuente de verdad

Para evitar documentación divergente:

- `CHANGELOG.md` = historial técnico.
- `readme.txt` = resumen público de la versión.
- `docs/` = documentación funcional y operativa.
- GitHub Wiki = publicación navegable de la documentación canónica.
- `main` = código aprobado para producción.
- `staging` = próxima versión candidata.
