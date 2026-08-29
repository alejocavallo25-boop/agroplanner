# Migraciones

## Cuál correr

**`esquema_completo.sql`** — es la única que hay que correr. Deja cualquier
base con el esquema al día: crea las tablas y columnas que falten, corrige la
precisión de las columnas de plata, y al final verifica y reporta lo que no
coincida.

Es idempotente: se puede correr sin saber qué tiene la base y se puede repetir
sin romper nada. No borra ni convierte datos nunca.

Antes de correrla en producción, hacer el backup que indica su PARTE 0.

## Las otras 20

Los demás `.sql` son las migraciones sueltas que se fueron aplicando con el
tiempo. **Quedan como historial — no correrlas.** Ninguna es idempotente: usan
`ADD COLUMN` pelado, así que fallan apenas encuentran algo ya aplicado. Y como
nunca hubo tabla que registrara cuáles corrieron, no hay forma de saber desde
cuál seguir. `esquema_completo.sql` existe justamente para no tener que saberlo.

Sí conservan algo que la consolidada no tiene: los `UPDATE` de datos, como
marcar la cuenta demo en solo lectura o prender los módulos de los usuarios
activos. Si alguna vez se levanta un entorno desde cero, esos van aparte.

## Cuando cambie el esquema

1. Aplicar el cambio en la base local.
2. `php migrations/generar_esquema_completo.php`
3. Commitear el `.sql` regenerado junto con el cambio de código.

El generador lee el esquema real de local, así que **local tiene que estar al
día**. Si a local le falta algo, el tipo viejo sale horneado en el archivo y se
propaga a producción. Ya pasó una vez: local tenía siete columnas de plata con
2 decimales que producción hacía rato tenía en 4, y de haber generado sin
mirar, la migración las habría "corregido" para atrás.

## Antes de deployar

El deploy no toca la base. Si el cambio de código necesita una columna nueva,
**la migración va primero**: agregarla con el código viejo andando es
inofensivo, pero publicar código que consulta una columna que todavía no existe
rompe el panel de todos.
