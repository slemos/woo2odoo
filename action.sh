#!/bin/bash -x
# receive argument with node directory
# $1 = node directory
# Set the path to the node directory
NODE_DIR=$1
# Include in path the node directory
export PATH=$PATH:$NODE_DIR

# Change working directory to user's home
HOME_DIR=$(eval echo ~)
echo "Home directory: $HOME_DIR"

# Set working directory
cd "$HOME_DIR"/woo2odoo
echo "Current working directory: $(pwd)"

# Install wp-env
npm i @wordpress/env@9.0.0

# Install composer
# cat .wp-env.json
# php composer.phar install

# ls -ltr "$HOME_DIR"/woo2odoo

#yes | wp-env destroy

# Start wp-env
npx wp-env start #--debug start

# Run composer install in test
npx wp-env run cli ls -ltrR ./wp-content/plugins/woo2odoo

# wp-env run cli --env-cwd=wp-content/plugins/woo2odoo wp plugin install woocommerce --activate

# wp-env run cli --env-cwd=wp-content/plugins/woo2odoo wp plugin list

npx wp-env stop