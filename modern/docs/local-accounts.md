# Local accounts — sign-in guide

Until Entra ID (Microsoft) sign-in is set up, the team signs in to COMET with
**local accounts**: an email address and a password stored in COMET. This is a
stop-gap. Once Entra works, switch local sign-in off (see the end of this page).

Everything here happens at `https://comet.phsa.ca`.

---

## For the administrator

### 1. Sign in as the admin

1. Open `https://comet.phsa.ca`. The sign-in page shows an email and password
   form.
2. Email: `admin@comet.local`
3. Password: the admin password. On a new server this is `change-me-now`.
4. Click **Sign in**.

If the password is still the default, or was reset, COMET takes you straight
to **Change password** and blocks the rest of the app until you pick a new
one. That is expected.

### 2. Change your password

1. **Current password**: the password you just signed in with.
2. **New password**: at least 12 characters. A short sentence is easiest,
   e.g. `mapping sheets every monday`.
3. **Confirm new password**: type it again.
4. Click **Save new password**. You go to the home page with the message
   *"Your password has been changed."*

You can change it again any time with **Change password** at the top right.
Changing it signs you out of every other browser.

Store the admin password in PHSA's password manager. Share it only with the
people who must be able to administer COMET.

### 3. Add a team member

1. Click **Users** in the top menu.
2. Scroll to **Add a local account**.
3. Enter their **Full name** and **Email**. The email is their sign-in name.
4. Tick their roles:

   | Role | Can |
   |---|---|
   | Mapper | map source terms to OMOP concepts |
   | Importer | import Cerner MappingReport files |
   | Reviewer | review and approve maps, see Impact and Team |
   | Admin | manage users and releases |

5. Click **Create account**.

A yellow box shows a **temporary password**, for example `JP2V-PtE7-DNhH-2U3D`.

- It is shown **only once**. Copy it now.
- Give it to the person **by phone or in person**. Do not email it together
  with their sign-in address.
- Click **I have passed it on — hide it**.

The person must choose their own password when they first sign in. Until
then, the **Password** column shows *pending*.

### 4. When someone forgets their password

1. **Users** → find the person → **Reset** in the Password column.
2. Confirm. COMET signs them out everywhere and shows a new temporary
   password, exactly as when you created the account.
3. Pass it on the same way.

You cannot reset your own password here. Use **Change password** instead.

### 5. When someone leaves or should lose access

**Users** → untick **Enabled** for that person. They are signed out
immediately and cannot sign in again. Tick it again to restore access.
Change roles the same way, by ticking or unticking the role columns.

Every change on the Users screen is recorded in the audit log, together with
who made it.

### 6. If nobody can sign in as admin

For example, the admin password was forgotten. Someone with access to the
server runs:

```bash
cd /opt/comet/modern
comet exec app php artisan comet:reset-password admin@comet.local
```

It prints a new temporary password. Sign in with it, and COMET asks you to
choose a new one. The same command works for any local account.

---

## For team members

### First sign-in

1. Open `https://comet.phsa.ca`.
2. Enter your email and the **temporary password** the admin gave you. It
   looks like `JP2V-PtE7-DNhH-2U3D`. Type the dashes as shown.
3. COMET asks you to **choose your own password**:
   - **Current password**: the temporary password
   - **New password**: at least 12 characters
   - **Confirm new password**: the same again
4. Click **Save new password**. You are now in COMET.

### Changing your password later

Click **Change password** at the top right and follow the same steps.

### Forgot your password?

Ask a COMET admin to reset it. They will give you a new temporary password.

### Too many attempts

After 5 wrong passwords within a minute, sign-in is blocked for that email
for about a minute. Wait and try again.

---

## Rules at a glance

| Rule | Detail |
|---|---|
| Password length | 12–72 characters |
| New password | must differ from the current one |
| Temporary passwords | 16 characters, look-alike characters left out (no `0`/`O`, `1`/`l`/`I`) |
| Wrong-password limit | 5 per minute per email and network address |
| After a reset or password change | the person is signed out of other browsers |
| Disabled account | signed out immediately, cannot sign in |
| Audit | account created, password reset, password changed, and role changes are logged with who did it |

---

## Server settings

Local sign-in only works when `.env.production` has:

```bash
AUTH_LOCAL_LOGIN=1
```

After changing it, run `comet up -d`.

### When Entra ID is ready

1. Set `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET` and `ENTRA_TENANT_ID`, then run
   `comet up -d`. The login page then shows **Sign in with Microsoft** as well.
2. Each team member signs in with Microsoft once. They appear on the Users
   screen as a new `entra` account **with no roles**. Grant their roles there.
3. Disable their old local accounts.
4. Set `AUTH_LOCAL_LOGIN=0` and run `comet up -d`. This turns off every local
   sign-in, including the break-glass admin. Turn it back on (`1`) only if
   Microsoft sign-in is down and you need emergency access.

## Why this is a stop-gap

Local passwords live in COMET. They have no multi-factor authentication, and
they are not disabled automatically when someone leaves PHSA. Entra ID
handles both, so move to it as soon as it is available.
