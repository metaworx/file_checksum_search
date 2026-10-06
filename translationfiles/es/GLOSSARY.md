# Spanish glossary

This translation is an AI draft, not yet reviewed by a native speaker.

The terms the Spanish translation, `es`, uses. A new text takes them from
here; a term that changes, changes here and in the `.po` file at once.

## Registers

- `es` addresses the reader as *usted*: *su*, *puede*, *cree*, *elija*,
  *póngase en contacto*. That is the form Nextcloud's own Spanish uses in
  most of its settings and core texts that speak to the user, for instance
  «Por favor, contacte con el administrador.» (core), «Necesita establecer
  el correo electrónico de su cuenta […]. Vaya a %s» (settings) and «No
  tiene permiso para subir o crear archivos aquí» (files). Nextcloud's
  Spanish mixes in *tú* in places; this app does not.
- A button, menu entry or switch names its action in the infinitive, as
  Nextcloud's own: «Añadir regla», «Volver a aplicar», «Tomar nota»,
  «Buscar en cualquier parte del hash». A tooltip that tells the reader
  what to do takes *usted*: «Arrastre para reordenar…».

## Terms

| English | Spanish | Note |
|---|---|---|
| checksum | suma de verificación, sumas de verificación | the English's word for the value; Nextcloud's Spanish has no term of its own. Feminine: adjectives and pronouns that refer to it agree («las desactualizadas», «Caducadas») |
| hash | hash, hashes (m.) | only where the English keeps *hash*: the Duplicates hash filter («Hash completo o parte de uno», «Buscar en cualquier parte del hash»), the «Hash» column, the `"hash"` parameter |
| hashing | cálculo de hashes | «El cálculo automático de hashes está inactivo.» |
| algorithm | algoritmo | |
| duplicates | duplicados | the page: «Duplicados» |
| rule | regla | Nextcloud: «Añadir nueva regla» |
| personal rule | regla personal | an account's rule for its own home folder; «su propia regla» where the English says *your own rule* |
| band | nivel, niveles | the ranked evaluation layer a rule falls in: «Nivel 7», «Nivel 7, posición 1», «Nivel de prioridad» |
| segment (in the code) | — | one scope's rules inside a band; internal, never shown |
| position | posición | a rule's place among the rules of the same scope in its band |
| priority | prioridad | |
| scope | ámbito | Nextcloud's `files_external`: «Ámbito»; «entre las reglas del mismo ámbito» |
| selector | selector | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | ubicación | what has, or lacks, a catch-all rule of its own |
| enforced | impuesta (regla impuesta; un administrador *impuso* la regla); the setting «Impuesta», the band labels «Impuestas — …» | Nextcloud's `files_sharing`: «Protección con contraseña (impuesta)» |
| specific | concreto, concreta | |
| catch-all rule | regla comodín | |
| default (rule) | regla predeterminada; «regla predeterminada comodín» for a catch-all default | |
| default (value) | predeterminado | Nextcloud: «Predeterminado» |
| Add rule | «Añadir regla» | the button, and the texts that name it |
| come before (a rule) | ir antes que | «ninguna regla personal puede ir antes que ella» |
| type (of a rule) | tipo | the field «Tipo»; «una regla de tipo %s»; a named type: regla de tipo «Incluir» |
| Include / Ignore / Exclude | Incluir / Ignorar / Excluir | a rule's *type*, as the Type field names it; the table's `"include"`, `"ignore"`, `"exclude"` stay English |
| mode (of a rule) | modo | the field «Modo»; never used for Include / Ignore / Exclude |
| Auto / Missing / Force / Lazy | Automático / Faltantes / Forzar / Diferido | a rule's *mode*, as the Mode field names it; the table's `"auto"`, `"missing"`, `"force"`, `"lazy"` stay English |
| match | coincidir; *hacer coincidir* where something is matched | «la primera regla que coincide», «Hacer coincidir el término en cualquier parte del hash» (the switch's tooltip); the switch's own label says *buscar*: «Buscar en cualquier parte del hash» |
| home folder | carpeta personal | |
| team folder | carpeta de equipo | Nextcloud's `groupfolders`: «Carpeta de equipo», «Carpetas de equipo»; the selector prefix `groupfolder:` is typed as it is |
| share (a received one) | recurso compartido | Nextcloud's term (*recurso compartido recibido*); the location prefix `share:` is typed as it is |
| storage | almacenamiento | |
| raw ID | ID interno | |
| external storage | almacenamiento externo | Nextcloud's `files_external`: «Almacenamiento externo» |
| received share | recurso compartido recibido | Nextcloud's *recurso compartido* |
| account | cuenta, cuentas | Nextcloud: «Cuentas» |
| user | usuario | only in «Guía del usuario»; everywhere else the English says *account* |
| this server | este servidor | not *esta instancia*; «la mayoría de los servidores» |
| administrator | administrador | Nextcloud: «contacte con el administrador» |
| admin group | grupo de administradores | |
| group admin | administrador de grupo | Nextcloud's word for a sub-admin; settings: «Administrador de grupo para» |
| app password | contraseña de aplicación | Nextcloud's core: «Contraseña de aplicación» |
| filesystem access | acceso al sistema de archivos | Nextcloud's settings: «Permitir acceso al sistema de archivos» |
| app | aplicación | |
| sudo token | token sudo | |
| grant (noun / verb) | autorización / autorizar | a sudo token's standing permission; it «sigue vigente hasta que se revoca» |
| revoke | revocar | Nextcloud's settings: «Revocar la contraseña de aplicación?» |
| permission | permiso | kept apart from *autorización* |
| cross-account | entre cuentas | the adjective: «rutas entre cuentas» |
| look across accounts | consultar otras cuentas | the verb: «Quién puede consultar otras cuentas» |
| sudoers | sudoers | |
| Mine / Others | Míos / Otros | the tabs |
| recalculate, recalculation | recalcular, recálculo | |
| verify | verificar | Nextcloud: «Verificar» |
| verified / failed / not processed | Verificado / Error / No procesado | a file's verification result; *Error* is a noun, so it needs no agreement |
| Whose files | Archivos de | the Others tab's picker label: it takes accounts and groups, and a group owns nothing |
| index (verb), indexed | indexar, indexado | |
| pending | pendiente | Nextcloud's files: «Pendiente» |
| queued files | archivos en cola | |
| untrusted / eroded / reset | no fiables / caducadas / invalidadas | the states of stale checksums, feminine to agree with *sumas de verificación*; *Reset* is the checksums a reset has made invalid, a past participle, not the action, so not *restablecidas*, which reads as "restored" |
| reset (the action) | restablecimiento | «invalidadas por un restablecimiento» |
| background job | trabajo en segundo plano | Nextcloud's settings: «Trabajos en segundo plano» |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check / checksum stamp check | Recorrido de reglas / Vaciado de la cola / Purga de entradas huérfanas / Copia de sumas de verificación existentes / Comprobación del índice de sumas de verificación / Comprobación de las marcas de tiempo de las sumas de verificación | the background jobs' names; *existentes* because the job copies what Nextcloud already holds, not a backup |
| reapply | volver a aplicar | never *nueva aplicación*, which reads as "new app" |
| Acknowledge / Noted | Tomar nota / Anotado | the idle banner's button, and the toast that answers it |
| tunables | Parámetros | |
| prefill | precargar, precarga | |
| picker | selector | Nextcloud: «selector de archivos» |
| glob pattern | patrón glob | |
| path | ruta | Nextcloud: «Ruta de carpeta inválida» |
| sidebar | barra lateral | |
| file sidebar | barra lateral de archivo | Nextcloud's files: «Abrir la barra lateral de archivo» |
| tab | pestaña | Nextcloud's `user_ldap`: «la pestaña Avanzado» |
| settings, personal settings | Ajustes, ajustes personales | Nextcloud: «Ajustes personales» |
| Security | Seguridad | Nextcloud's personal settings section |
| enable / disable; enabled / disabled | activar / desactivar; activada / desactivada | one pair throughout, as Nextcloud's settings buttons |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes Spanish angle quotes and the exact Spanish
  of that element's own label: «Todas las carpetas personales»,
  «Seguridad», «Duplicados», «Añadir regla», a type as «Incluir». A value
  shown or typed as it is keeps straight quotes and stays English: `"**"`,
  `"include"`, `"—"`, `"hash"`, `local::/path/`.
- A failure names what failed: «No se pudo guardar la regla.» One with
  nothing to name reads «No se pudo guardar.», as the English's "Saving
  failed.".
- A label that pairs two parts keeps the spaced em dash of the source:
  «Impuestas — todo», «{folders} — una carpeta». In running text an English
  dash becomes Spanish punctuation: a colon or semicolon between clauses,
  parentheses around a path list or an apposition, and the *raya* for an
  aside, `—esta, la página «Duplicados»—`, attached to the words it
  encloses.
- An ellipsis follows the word without a space, as Spanish typography
  has it: «Buscando…», «Guardando…», as Nextcloud's own «Guardando…». The
  English's no-break space before "…" is not carried over. Written as the
  character itself, never three dots.
- Questions and exclamations open with their inverted sign: «¿Eliminar
  esta regla?», «¡Copiado!».
- A relative clause that sets a filter takes the subjunctive: «los grupos
  cuyo hash coincida con lo que escriba».
- Where a verb could take the wrong subject, *usted* is spelled out:
  «el selector consulta al servidor mientras usted escribe».
- A switch is turned on, *activar*, not ticked, *marcar*.
- A date placeholder reads as a date: «Autorizada el {time}».
- `{placeholders}`, `%s` and `%n` stay as they are.
