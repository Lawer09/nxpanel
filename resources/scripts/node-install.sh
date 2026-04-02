SCRIPT_REPO="${SCRIPT_REPO:-Lawer09/ad2nx-s}"
SCRIPT_BRANCH="${SCRIPT_BRANCH:-master}"
export API_HOST="https://pupu.apptilaus.com"
wget -O auto-install.sh "https://raw.githubusercontent.com/${SCRIPT_REPO}/${SCRIPT_BRANCH}/auto-install.sh" && bash auto-install.sh