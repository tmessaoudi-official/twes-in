# SPDX-License-Identifier: AGPL-3.0-or-later
#
# GlitchTip's side of a new monitor (docs/START.md § 15), run once the operator's account exists:
#   docker compose … exec -T glitchtip python manage.py shell < infra/monitor/bootstrap.py
# with OPERATOR_EMAIL set in the container's environment (exec -e). It makes the one organisation, team and project the
# instance reports to, makes the operator their owner, and prints the project's DSN, the instance's SENTRY_DSN. Run again,
# it changes nothing and prints the same DSN.
import os
import sys

from allauth.account.models import EmailAddress

from apps.organizations_ext.models import Organization
from apps.projects.models import Project, ProjectKey
from apps.teams.models import Team
from apps.users.models import User

NAME = "twes-in"

email = os.environ.get("OPERATOR_EMAIL", "")
user = User.objects.filter(email=email).first()
if not email or user is None:
    sys.exit(f"bootstrap: no account for OPERATOR_EMAIL={email!r}: create it first (manage.py createsuperuser)")
# The operator's own address: no verification mail is waited for on the one account a monitor has.
EmailAddress.objects.get_or_create(user=user, email=email, defaults={"primary": True, "verified": True})

organization, _ = Organization.objects.get_or_create(slug=NAME, defaults={"name": NAME})
if not organization.users.filter(pk=user.pk).exists():
    organization.add_user(user)
project, _ = Project.objects.get_or_create(slug=NAME, organization=organization, defaults={"name": NAME})
team, _ = Team.objects.get_or_create(slug=NAME, organization=organization)
team.members.add(organization.organization_users.get(user=user))
team.projects.add(project)

key = ProjectKey.objects.filter(project=project, is_active=True).first() or ProjectKey.objects.create(project=project)
print(f"SENTRY_DSN={key.get_dsn()}")
