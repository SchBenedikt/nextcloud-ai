# Use the official Python image from the Docker Hub, specifying the version and OS
FROM python:3.10.14-slim-bullseye

# Copy the requirements.txt file into the container
COPY requirements.txt requirements.txt

# Install the Python dependencies specified in requirements.txt
RUN pip3 install -r requirements.txt

# Copy the main application file and the models file into the container
COPY main.py main.py
COPY models.py models.py

# Specify the command to run the application
CMD [ "python3", "-m" , "main"]
