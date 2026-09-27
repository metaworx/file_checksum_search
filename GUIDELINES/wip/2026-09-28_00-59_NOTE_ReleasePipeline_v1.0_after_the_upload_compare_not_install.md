# NOTE ReleasePipeline v1.0: after the upload, compare, do not install

Accompanies `2026-09-27_22-31_AP_ReleasePipeline_v1.0_sign_verify_publish_confirm.md`
and its two earlier notes. Where they differ, this note holds.

## Block 4 replaced: the store's entry is compared, not installed from

The installs before the upload (block 3) prove that servers install the
release from a listing entry the fake store built. After the upload, the
open question is only whether the store's own entry says the same. So the
post-upload installs go, and the `listed` job does four things:

1. **Waits for the first host** that lists the release: it follows the
   store's redirects on fresh connections, asks every host seen so far
   directly on every round, and uses each host's ETag so an unchanged
   listing costs a 304.
2. **Compares the store's entry** with the one the fake store serves, built
   by the same code from the manifest, the download URL and the signature:
   version, nightly flag, download, signature, platform and PHP ranges, and
   the certificate against the one the release was signed for. Any
   difference fails the job: the store processed the release differently
   from what the installs tested.
3. **Waits for every host seen**, up to a limit, and writes into the run's
   summary which hosts it saw and how long each took. Convergence is
   reported, not gated: it is a matter of time, not of correctness.
4. **Comments on the release commit**, mentioning the maintainers
   (`vars.RELEASE_NOTIFY`, or whoever pushed the tag): the release, the
   hosts, the times. GitHub e-mails the mentioned accounts. Issues and
   discussions are switched off on the repository; a webhook or direct
   SMTP were the alternatives, and direct delivery would fail from
   GitHub's runners, whose network blocks outbound port 25.

The install check keeps only the fake store; its `real` mode, which waited
on the platform listing a server never reads, goes with it.

## How the hosts are known: by observation

The store publishes no list of mirrors. It answers `api/v1/apps.json`
itself or redirects to a mirror. Measured on 2026-09-28, 00:40 CEST, from
the maintainer's machine:

| What | Result |
|---|---|
| 40 requests to `apps.nextcloud.com/api/v1/apps.json` | 14 served directly, 14 redirected to `garm2`, 12 to `garm3` |
| DNS `garm1` … `garm7.nextcloud.com` | all resolve (IPv6 in `2a01:4f9::/32`); `garm8`, `garm9` do not |
| `garm2`, `garm3`: HEAD, GET on the listing | 200, both `last-modified: Sun, 27 Sep 2026 22:13:14 GMT` |
| `garm1`, `garm4` … `garm7`: HEAD / GET on the listing | 405 / 401, nginx: not listing mirrors |

So from here the listing comes from three places: the store itself,
`garm2` and `garm3`. A mirror the store only hands to other regions, if one
exists, cannot be seen from a runner; the report names the hosts it saw,
so its claim is as wide as the observation.

The probe, for repeating it:

```bash
for i in 1 2 3 4 5 6 7 8 9; do h=garm$i.nextcloud.com; ip=$(getent hosts $h | awk '{print $1}' | head -1); code=$( [ -n "$ip" ] && curl -s -o /dev/null -I -w "%{http_code}" --max-time 20 "https://$h/api/v1/apps.json" ); lm=$( [ -n "$ip" ] && curl -sI --max-time 20 "https://$h/api/v1/apps.json" | grep -i "^last-modified" | cut -d' ' -f2- | tr -d '\r' ); echo "$h ${ip:-no DNS} ${code:-} ${lm:-}"; done
```

```text
garm1.nextcloud.com 2a01:4f9:5a:1299::2 405
garm2.nextcloud.com 2a01:4f9:3051:40eb::2 200 Sun, 27 Sep 2026 22:13:14 GMT
garm3.nextcloud.com 2a01:4f9:1a:995e::2 200 Sun, 27 Sep 2026 22:13:14 GMT
garm4.nextcloud.com 2a01:4f9:1a:9254::2 405
garm5.nextcloud.com 2a01:4f9:3080:4f42::2 405
garm6.nextcloud.com 2a01:4f9:3090:1c0f::2 405
garm7.nextcloud.com 2a01:4f9:3070:2f0d::2 405
garm8.nextcloud.com no DNS
garm9.nextcloud.com no DNS
```

A plain GET, not a HEAD, on `garm1` and `garm4` … `garm7` answered
`401 Unauthorized`.
