FROM php:8.4-apache-bookworm
RUN php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY src /app/src
COPY public /app/public
COPY bin /app/bin
COPY entrypoint.sh /app/entrypoint.sh
RUN chmod 755 /app/entrypoint.sh
ENV STATS_DATA=/data
VOLUME /data
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["apache2-foreground"]
