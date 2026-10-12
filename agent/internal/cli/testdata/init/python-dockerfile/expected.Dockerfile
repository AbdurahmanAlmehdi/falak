# Starter Dockerfile written by `falak init --dockerfile`: check the build and start commands.
FROM python:3.13-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PORT=8000
WORKDIR /app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
EXPOSE 8000
USER nobody
CMD ["sh", "-c", "uvicorn main:app --host 0.0.0.0 --port $PORT"]
