# Gitlab NPM repository

Small script that loops through all branches and tags of all projects in a Gitlab installation
and if it contains a `package.json`, and has a non empty `name` it is added.

This script is not a full repository like npmjs.com, but only implements the install command.

## Installation

 1. Run `composer install`
 2. Copy `.env.example` to `.env`
 3. Get a "personal access token" with scopes: `api,read_repository`
 4. For some reason npm sends `/` like `%2F`, so you need to set `AllowEncodedSlashes NoDecode` inside your `<VirtualHost>`

## Usage

Simply include a package.json in your project, all branches and tags using
[semver](https://semver.org/) will be detected. "v1.2.3" will be converted to "1.2.3"

To use your repository, use this command to install the packages:
```
npm install project-name --registry http://npm.gitlab.localhost/
```

## Warning

This script could allow access to private repositories. Because I haven't figured out the 
correct way for npm authorization, the current .htaccess restricts to local usage. 
So be sure to update the .htaccess to match your requirements.

## Author
 * [Maglr](https://github.com/maglr)
