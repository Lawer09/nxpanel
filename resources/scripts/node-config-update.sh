SCRIPT_REPO="${SCRIPT_REPO:-Lawer09/ad2nx-s}"
SCRIPT_BRANCH="${SCRIPT_BRANCH:-master}"
wget -O auto-install.sh "https://raw.githubusercontent.com/${SCRIPT_REPO}/${SCRIPT_BRANCH}/update-config.sh" && bash update-config.sh