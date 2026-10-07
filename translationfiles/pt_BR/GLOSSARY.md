# Brazilian Portuguese glossary

This translation is an AI draft that no native speaker has reviewed yet.

The terms the `pt_BR` translation uses. A new text takes them from here; a
term that changes, changes here and in the `.po` file at once.

## Registers

- `pt_BR` addresses the reader as *você*, as Nextcloud's own `pt_BR` does
  ("Você não tem permissão para…", "Tente novamente ou entre em contato
  com o administrador"): possessives *seu*, *sua*; instructions in the
  imperative that goes with *você* (*ative*, *adicione*, *escolha*,
  *aguarde*).
- Buttons, menu entries and headings are infinitives or nouns, as in
  Nextcloud: *Salvar*, *Excluir*, *Adicionar regra*, *Marcar como ciente*,
  *Configurações*.

## Terms

| English | Brazilian Portuguese | Note |
|---|---|---|
| checksum | soma de verificação, somas de verificação | the English's word for the value; feminine: *as somas de verificação já calculadas* |
| hashing, hash (verb) | cálculo de somas de verificação, calcular somas de verificação | the English keeps *hashing* for the act, but what it computes is the checksum: *O cálculo de somas de verificação está bloqueado*, *nunca calcular somas de verificação destes arquivos* |
| hash | hash, hashes (masculine) | only where the English keeps *hash*: the Duplicates hash filter, “Encontrar em qualquer parte do hash”, *Hash inteiro ou parte dele*, the `"hash"` parameter |
| algorithm | algoritmo | |
| duplicates | duplicatas | the page is “Duplicatas” |
| rule | regra | |
| personal rule | regra pessoal | an account's rule for its own home folder; not *regra própria de uma conta* |
| band | faixa | the evaluation layer a rule falls in; not *nível* or *camada*: “Faixa 7, posição 1”, *dentro desta faixa*, *para outra faixa* |
| position | posição | a rule's place among the rules of the same scope in its band |
| priority | prioridade | |
| scope | escopo | Nextcloud's settings term; *entre as regras do mesmo escopo* |
| selector | seletor | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | local, locais | what has, or lacks, a catch-all rule of its own |
| enforced | imposta, impostas | a rule; the field in the form is “Imposta” |
| catch-all rule | regra abrangente | kept apart from *regra mais geral* (a more general rule); the default one is *regra padrão abrangente* |
| default (rule, value) | padrão | *regra padrão*, *algoritmo padrão* |
| Add rule | Adicionar regra | the button, and the text that names it |
| type (of a rule) | tipo | the rule's include / ignore / exclude; in running text *uma regra do tipo “Incluir”* |
| mode (of a rule) | modo | the rule's auto / missing / force / lazy |
| Include / Ignore / Exclude | Incluir / Ignorar / Bloquear | a rule's *type* (*Tipo*), never its mode; *Excluir* is Nextcloud's *Delete*, so Exclude is *Bloquear*, and "hashing is excluded" is *está bloqueado* |
| Auto / Missing / Force / Lazy | Automático / Ausentes / Forçar / Adiado | a rule's *mode* (*Modo*), never its type |
| home folder | pasta pessoal | Nextcloud's term |
| team folder | pasta de equipe | Nextcloud's term (Team folders app); the selector prefix `groupfolder:` is typed as it is |
| share (a received one) | compartilhamento | Nextcloud's term (*compartilhamento recebido*); the location prefix `share:` is typed as it is |
| address (a rule's scope as one string) | endereço | `home:alice//Documents/**` is typed as it is |
| storage | armazenamento | |
| external storage | armazenamento externo | |
| raw ID | ID interno | *o ID*, masculine, as most of Nextcloud's `pt_BR` has it |
| account | conta, contas | Nextcloud's *Contas* |
| this server | este servidor | not *esta instância*: *neste servidor*, *a maioria dos servidores* |
| administrator | administrador | as Nextcloud's `pt_BR` has it |
| admin group | grupo de administradores | |
| group admin (sub-admin) | administrador de grupo | Nextcloud's *Admin. de grupo* |
| app password | senha de aplicativo | Nextcloud's term |
| Security (the personal settings section) | Segurança | Nextcloud's own name |
| Allow filesystem access | Permitir acesso ao sistema de arquivos | Nextcloud's own option; *filesystem access* is *acesso ao sistema de arquivos* |
| sudo token | token sudo | |
| grant (noun / verb) | concessão / conceder (acesso) | Nextcloud's *conceder acesso*; what is granted is *acesso*, and a granted app password is one *com acesso concedido*; a grant *vale até ser revogada* |
| revoke | revogar | Nextcloud's term |
| cross-account | entre contas | the nouns: *Leituras entre contas*, *Seletor entre contas*, *rotas entre contas* |
| look across accounts | consultar outras contas | the permission: *Quem pode consultar outras contas*; other accounts' files: *consultar os arquivos de outras contas* |
| read across accounts | ler dados de outras contas | not *ler entre contas*, which reads as "between accounts" |
| find across accounts | encontrar em todas as contas | the link: *Encontrar em todas as contas*, the viewer's own included, as its tooltip says |
| sudoers | sudoers | |
| picker | seletor | |
| Mine / Others | Meus / De outros | the tabs |
| recalculate | recalcular, recálculo | |
| by hand | manualmente | *recalcular somas de verificação manualmente* |
| verify | verificar | |
| reapply | reaplicar, reaplicação | |
| Acknowledge / Noted | Marcar como ciente / Marcado como ciente | the banner's button and its confirmation |
| indexed | indexado, indexadas | |
| queued files | arquivos na fila | |
| untrusted / eroded / reset | não confiáveis / descartadas / redefinidas | the states of stale checksums, feminine plural agreeing with *somas de verificação*; *Redefinidas* is a past participle, checksums a reset has made invalid, never the action *Redefinir* |
| invalidated (by a reset) | invalidadas | |
| background job | tarefa em segundo plano | Nextcloud's term; *Ainda não executada* agrees with it |
| rule sweep / rule reapplication / queue drain / orphan purge / checksum copy / checksum index check / checksum stamp check | Varredura de regras / Reaplicação de regra / Processamento da fila / Limpeza de entradas órfãs / Cópia de somas de verificação existentes / Conferência do índice de somas de verificação / Conferência dos carimbos de data e hora das somas de verificação | *Conferência*, not *Verificação*: keeps the job apart from the Duplicates page's *Verificar* |
| tunables | ajustes finos | |
| prefill | pré-preencher | |
| glob pattern | padrão glob | |
| path | caminho | |
| match (a rule matches) | corresponder, correspondência | the hash filter's switch says *encontrar*: “Encontrar em qualquer parte do hash” |
| sidebar | barra lateral | Nextcloud's term |
| file sidebar | barra lateral de arquivo | Nextcloud's own term (“Abrir barra lateral de arquivo”) |
| Unified Search | Pesquisa unificada | Nextcloud's term (store description) |
| tab | aba | |
| settings | configurações | Nextcloud's term |
| search (verb) | pesquisar | Nextcloud's term |
| delete | excluir | Nextcloud's term |
| enable / disable | ativar / desativar | Nextcloud's terms |
| write to (a folder) | gravar | |
| received share | compartilhamento recebido | |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes curly quotes and the exact translation of
  that element's own label, as Nextcloud's `pt_BR` does: “Todas as pastas
  pessoais”, “Adicionar regra”, “Segurança”, a type as “Incluir”. A value
  shown or typed as it is keeps straight quotes and stays English: `"**"`,
  `"include"`, `"hash"`, `local::/path/`.
- A failure names what failed: *Não foi possível salvar a regra.* One with
  nothing to name reads *Falha ao salvar.*, as the English's "Saving
  failed.".
- A dash between clauses is the em dash with spaces: ` — `.
- An ellipsis follows the word without a space, the English's no-break
  space dropped: *Pesquisando…*, *Salvando…*.
- `{placeholders}`, `%s` and `%n` stay as they are.
- The plural has three forms: 0 and 1, exact millions (*%n de arquivos*),
  and the rest.
