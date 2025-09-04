# Make sure you're on the right branch
git checkout main

# Pull remote changes into your local repo
git pull origin main --allow-unrelated-histories

# Resolve any merge conflicts if prompted, then:
git add .
git commit -m "Merge remote main into local main"

# Push again
git push origin main
