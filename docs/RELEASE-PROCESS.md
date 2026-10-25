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

El `readme.txt` raíz queda asociado al monolito 2.4.1. Cada plugin `taxocommerce-*` mantiene su propio `readme.txt`, que es su historial y documento público de versión.

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

## 4. Versionado modular desde el milestone 2.4.2

Desde el milestone 2.4.2 no existe una versión global que deba aplicarse a todos los plugins.

Ejemplo:

```text
TaxoCommerce Core       0.4.28 -> 0.4.29
TaxoCommerce Images     0.2.8  -> 0.2.9
TaxoCommerce Auditor    0.x    -> 0.x+1
```

Sólo incrementa versión el plugin cuyo código cambia. Cada plugin registra sus cambios en su propio `readme.txt`. Los milestones globales coordinan el trabajo, pero no sustituyen al versionado individual.

El `CHANGELOG.md` raíz y el `readme.txt` raíz permanecen congelados en 2.4.1 salvo correcciones críticas específicas del monolito.

---

## 4 bis. Sección Unreleased histórica

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

Para plugins independientes, comprobar coherencia únicamente dentro del plugin afectado:

- cabecera `Version:` de `taxocommerce-<plugin>.php`;
- constante de versión propia, cuando exista;
- `Stable tag` de su `readme.txt`;
- entrada correspondiente de su changelog.

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

- **TaxoCommerce SEO monolítico:** cerrado en `2.4.1`.
- **Milestones globales (`2.4.2`, posteriores):** coordinan trabajo y no son versiones del monolito.
- **Plugins independientes:** cada uno evoluciona con su propio versionado (`0.4.29`, `0.2.9`, etc.).
- Cambiar un plugin no obliga a versionar ni publicar los demás.
- El changelog canónico de cada plugin independiente vive en su propio `readme.txt`.
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

- `CHANGELOG.md` raíz = histórico congelado del monolito hasta 2.4.1.
- `readme.txt` raíz = documento público final del monolito 2.4.1.
- `taxocommerce-*/readme.txt` = versión e historial canónico de cada plugin independiente.
- `docs/` = documentación funcional y operativa.
- GitHub Wiki = publicación navegable de la documentación canónica.
- `main` = código aprobado para producción.
- `staging` = próxima versión candidata.
