# Validación de Import-Export Pro 0.1.0

1. En una instalación de prueba autorizada, activar Pro junto a Lite y, si ya está instalado, el módulo original. Verificar cabecera propia y ausencia de duplicidades.
2. Importar dos CSV de productos en el mismo bloque; comprobar que el prechequeo no escribe. Confirmar y verificar altas/actualizaciones por SKU/slug; repetir sin duplicar.
3. Repetir con dos CSV de páginas y con categorías/entradas/etiquetas en sus bloques. Las relaciones han de existir antes del prechequeo.
4. Probar un segundo CSV incorrecto: confirmar debe permanecer bloqueado y no guardarse ningún archivo del lote.
5. Pausar durante un trabajo, recargar y reanudar. Verificar que los demás bloques no cambian. Cambiar entre velocidades 1–5 y comprobar métricas.
6. Generar exportaciones grandes por tipo; descargar tras terminar y verificar filas, cabeceras y recuperación sin duplicados.
7. Archivar el trabajo y comprobar historial. Verificar revocación de permisos, aislamiento entre usuarios, nonce y temporales privados.
8. Ejecutar chequeo funcional, chequeo de código y Plugin Check oficial. Probar WP-Cron real con la pestaña cerrada y la configuración de hosting.
9. No activar ni desplegar en producción sin autorización expresa.
