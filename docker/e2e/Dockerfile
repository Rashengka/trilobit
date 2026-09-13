# The image bin/e2e-docker.mjs runs the browser suite in.
#
# It is Playwright's own image and not one assembled here, because the point of
# running in it is to be the Linux a build server is: the browsers, their system
# libraries and the fonts are exactly the ones that release of Playwright was
# tested with. The version is not written here - the script reads it out of
# package-lock.json and passes it in, so the browsers can never be of a
# different release than the test runner npm installs next to them.
ARG PLAYWRIGHT_VERSION
FROM mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble

# PHP, because the suite is not only a browser. The web server Playwright starts
# is PHP's own, and several specs make their accounts by running bin/trilobit -
# so a runner that has only Node cannot get past the first of them.
#
# 8.4 from the ondrej/php archive, because Ubuntu 24.04 itself stops at 8.3 and
# composer.json asks for at least 8.4. It is also where the CI job's PHP comes
# from: setup-php installs it from the same archive on an Ubuntu runner.
#
# The extensions are the ones the application needs beyond what the CLI package
# carries: the same set the local php container adds to its base image, where
# only pdo_mysql is missing - Debian's php image compiles mbstring and the XML
# family in, Ubuntu's packages ship them separately.
RUN apt-get update \
    && apt-get install -y --no-install-recommends software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get install -y --no-install-recommends \
        php8.4-cli \
        php8.4-mysql \
        php8.4-mbstring \
        php8.4-xml \
    && apt-get purge -y --auto-remove software-properties-common \
    && rm -rf /var/lib/apt/lists/*

# Assertions on, to match what CI and the local php container run with.
RUN echo 'zend.assertions=1' > /etc/php/8.4/cli/conf.d/99-trilobit.ini
